<?php


namespace Tests\Unit;


use App\Models\Rule;
use App\Models\User;
use App\Models\Variable;
use App\Services\Curl\CurlService;
use App\Services\ManticoreService;
use App\Services\VariablesService;
use Auth;
use Mockery;
use Tests\TestCase;

/**
 * @group application
 */
class ManticoreServiceTest extends TestCase
{

    /** @var ManticoreService */
    private $manticoreService;
    private $curl;

    protected function setUp(): void
    {
        parent::setUp();

        $this->curl             = Mockery::mock(CurlService::class);
        $this->manticoreService = new ManticoreService('');
        self::assertEmpty($this->manticoreService->getError());
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    public function testTruncateRules(): void
    {
        $this->manticoreService->truncateRules();
        $cnt = $this->manticoreService->countRules();
        self::assertEquals(0, $cnt[0]['count']);
    }


    /**
     * @test
     */
    public function failedInsertReturnsError(): void
    {

        $rule = new Rule();
        $rule->setQuery('@()?$#');
        $insert = $this->manticoreService->addRule($rule, null, false);
        self::assertSame(['message'=>"P08: syntax error, unexpected ')' near ')?$#'"], $insert);
    }

    /**
     * @dataProvider simpleQueryProvider
     *
     * @param $query
     * @param $filters
     * @param $tag
     * @param $external
     * @param $id
     *
     * @throws \JsonException
     */

    public function testSimpleInsert($query, $filters, $tag, $external, $id): void
    {
        $rule = new \App\Models\Rule();
        $rule->setId($id);
        $rule->setQuery($query);
        $rule->setFilters($filters);
        $rule->getTags()->setTag($tag);
        $rule->getTags()->setExternalQuery($external);
        $insert = $this->manticoreService->addRule($rule, null, false);
        self::assertEquals('Rule added', $insert['message']);
    }

    public static function simpleQueryProvider(): array
    {
        //$query, $filters, $tags, $external, $ruleId
        return [
            ['pizza', '', 'tag / ag', null, null],
            ['manti', '', '{"a":"b"}', 'externalQuery', 0],
            ['testText', '', 'someTag', 'externalQuery', 0],
            ['', 'json.lang="en"', '', '', 0],
            ['"non empty query"', 'json.lang="en"', '', '', 999299],
            ['(iphone|iphone5)', '', 'auto_tests_tag_1', '', 0],
            ['\@nba', '', '', '', 0],
            ['search query "@urlabc" manticoresearch.com', '', '', '', 0],
            ['svnba', '', '\\x32', '', 0],
            ['svnbac', '', '', '\\x32', 0],
            ['svnba', '', '\\x32(q)', '', 0],
            ['svnbac', '', '', '\\x32(q)', 0],
            ['svnbac', '', '', 'query=(\"andy penn\")&filter_language=en', 0],
            [
                '(("beard" NEAR/4 ("wash" | "Coloring")))',
                '',
                'tag',
                '(("beard" NEAR/4 ("wash" | "Coloring")))',
                0,
            ],
            [
                'svnbac', '', '', 'query=(achmea| avero| centraalbeheer| centraal beheer| defriesland| \"de friesland\"| eurocross| fbto| '.
                'independer| inshared| interpolis| syntrus| roadguard| zilverenkruis| \"zilveren kruis\")&dn=youtube.com', 0,
            ],
            ['Manticore AND Streams OR hello MAYBE wOrld', '', '', 'Manticore AND Streams OR hello MAYBE wOrld', 0],
            ["Trello -world Nello !wash", '', 'someTag', 'externalQuery', 0],
            ['"the world is a wonderful place"/3', '', '', '"the world is a wonderful place"/3', 0],
            ['"hello World"~10', '', '', '"hello World"~10', 0],
            ['"exact * phrasE * * for terms"', '', '', '"exact * phrasE * * for terms"', 0],
            ['(bag of words) << "Exact phrase" << red|grEEn|blue', '', '', '(bag of words) << "Exact phrase" << red|grEEn|blue', 0],
            ['^Hello World$', '', '', '^Hello World$', 0],
            ['boosted^1.234 boostedfieldend$^1.234', '', '', 'boosted^1.234 boostedfieldend$^1.234', 0],
            ['hello NEAR/3 World NEAR/4 "my test"', '', '', 'hello NEAR/3 World NEAR/4 "my test"', 0],
            ['Church NOTNEAR/3 street', '', '', 'Church NOTNEAR/3 street', 0],
            ['all SENTENCE words SENTENCE "in one sentence"', '', '', 'all SENTENCE words SENTENCE "in one sentence"', 0],
            ['"Bill Gates" PARAGRAPH "Steve Jobs"', '', '', '"Bill Gates" PARAGRAPH "Steve Jobs"', 0],
            ['ZONE:th hello people', '', '', 'ZONE:th hello people', 0],
            ['ZONESPAN:th Works fine', '', '', 'ZONESPAN:th Works fine', 0],
        ];
    }


    /**
     * @dataProvider urlQueryProvider
     *
     * @param $query
     * @param $hashed
     *
     * @throws \JsonException
     */
    public function testUrlInsert($query, $hashed): void
    {
        $rule = new \App\Models\Rule();
        $rule->setQuery($query);
        $insert = $this->manticoreService->addRule($rule, null, true);
        self::assertEquals('Rule added', $insert['message']);

        $insertRuleId = $this->manticoreService->getlastInsertId();

        $rule = $this->manticoreService->searchRuleExtended(1, 0, 0, 'desc', $insertRuleId);


        self::assertGreaterThan(0, $rule['data'][0]->getId());
        /**
         * @var $newRule \App\Models\Rule
         */
        $newRule = $rule['data'][0];

        self::assertEquals(strtolower($hashed), trim($newRule->getQuery()));
        self::assertEquals(strtolower($query), $newRule->getTags()->getOriginalQuery());
    }

    public static function urlQueryProvider(): array
    {
        //$query, $hashed
        return [
            [
                'generalquery @text querytofind @(merged, merged2) https://mail.google.com/@abc',
                'generalquery @text querytofind  @merged_host_path 4d236d9a2d102c5fe6ad1c50da4bec50 '.
                '1d5920f4b44b27a802bd77c4f0536f5a be5cab0695415d9363d18ad1345c73eb fda9c4564f22009f681016ad131410d6 '.
                '@merged2_host_path 4d236d9a2d102c5fe6ad1c50da4bec50 1d5920f4b44b27a802bd77c4f0536f5a '.
                'be5cab0695415d9363d18ad1345c73eb fda9c4564f22009f681016ad131410d6',
            ],
            [
                'generalquery @text querytofind @(merged, merged2) https://mail.google.com/',
                'generalquery @text querytofind  @merged_host_path 4d236d9a2d102c5fe6ad1c50da4bec50 '.
                '1d5920f4b44b27a802bd77c4f0536f5a be5cab0695415d9363d18ad1345c73eb @merged2_host_path '.
                '4d236d9a2d102c5fe6ad1c50da4bec50 1d5920f4b44b27a802bd77c4f0536f5a be5cab0695415d9363d18ad1345c73eb',
            ],
            [
                'generalquery @text querytofind @merged manticoresearch.com | https://mail.google.com/',
                'generalquery @text querytofind '.
                '(@merged_host_path 96ef039d822f1cec48a7410eccdf598b 60041c5cd8e2edbb9177f73592d2f164) | '.
                '(@merged_host_path 9b42dc9339ebf0c4121b9c8155c19f77 68278dfe0d8017d80633478b6f0e6f40)',
            ],
            [
                '@(merged, merged2) https://mail.google.com/',
                '@merged_host_path 4d236d9a2d102c5fe6ad1c50da4bec50 1d5920f4b44b27a802bd77c4f0536f5a '.
                'be5cab0695415d9363d18ad1345c73eb @merged2_host_path 4d236d9a2d102c5fe6ad1c50da4bec50 '.
                '1d5920f4b44b27a802bd77c4f0536f5a be5cab0695415d9363d18ad1345c73eb',
            ],
            [
                '@(merged, merged2) https://mail.google.com/ -manticoresearch.com',
                '@merged_host_path 4d236d9a2d102c5fe6ad1c50da4bec50 1d5920f4b44b27a802bd77c4f0536f5a '.
                'be5cab0695415d9363d18ad1345c73eb -(@merged_host_path 4d236d9a2d102c5fe6ad1c50da4bec50 '.
                'ed0bd936751e99c169b7f662e46ce192) @merged2_host_path 4d236d9a2d102c5fe6ad1c50da4bec50 '.
                '1d5920f4b44b27a802bd77c4f0536f5a be5cab0695415d9363d18ad1345c73eb '.
                '-(@merged2_host_path 4d236d9a2d102c5fe6ad1c50da4bec50 ed0bd936751e99c169b7f662e46ce192)',
            ],
            [
                '@merged https://www.youtube.com/watch?v=4w_OVYeea6c&ab_channel=WDRRockpalast',
                '@merged_host_path 4d236d9a2d102c5fe6ad1c50da4bec50 14dd5266c70789bdc806364df4586335 '.
                'ab3201c6103205c14f6e56b11b2fcd46 5f4f8643ce2564c52b52ce2684e62155  '.
                '@merged_query 107d180b349f1899248847729977522a',
            ],
            [
                '@merged https://manual.manticoresearch.com/Introduction#JSON-over-HTTP',
                '@merged_host_path 4d236d9a2d102c5fe6ad1c50da4bec50 ed0bd936751e99c169b7f662e46ce192 '.
                'c8803b11953ad72531b5873afd74fb8a b54d21da4003d8aa213326dfd4bc951a  @merged_anchor '.
                'c8b3ed28c81045ddea92ef169fec23a2',
            ],
            [
                'search query @merged manticoresearch.com',
                'search query @merged_host_path 4d236d9a2d102c5fe6ad1c50da4bec50 ed0bd936751e99c169b7f662e46ce192',
            ],
            [
                '@merged manticoresearch.com | https://mail.google.com/',
                '(@merged_host_path 96ef039d822f1cec48a7410eccdf598b 60041c5cd8e2edbb9177f73592d2f164) | '.
                '(@merged_host_path 9b42dc9339ebf0c4121b9c8155c19f77 68278dfe0d8017d80633478b6f0e6f40)',
            ],
            [
                '@merged manticoresearch.com | https://mail.google.com/ -https://app.tmetric.com',
                '(@merged_host_path 96ef039d822f1cec48a7410eccdf598b 60041c5cd8e2edbb9177f73592d2f164) | '.
                '(@merged_host_path 4d236d9a2d102c5fe6ad1c50da4bec50 1d5920f4b44b27a802bd77c4f0536f5a '.
                'be5cab0695415d9363d18ad1345c73eb) '.
                '-(@merged_host_path 4d236d9a2d102c5fe6ad1c50da4bec50 1ee5272525893bcc5d6375a5c37f56f4 '.
                'ca0960c70adb11d8774b26037882a1cd)',
            ],
            [
                '@(merged2) -https://mail.google.com/',
                '-(@merged2_host_path 4d236d9a2d102c5fe6ad1c50da4bec50 '.
                '1d5920f4b44b27a802bd77c4f0536f5a be5cab0695415d9363d18ad1345c73eb)',
            ],
            [
                '@merged -https://mail.google.com/',
                '-(@merged_host_path 4d236d9a2d102c5fe6ad1c50da4bec50 '.
                '1d5920f4b44b27a802bd77c4f0536f5a be5cab0695415d9363d18ad1345c73eb)',
            ],
            [
                'search query @merged -https://mail.google.com/',
                'search query  -(@merged_host_path 4d236d9a2d102c5fe6ad1c50da4bec50 '.
                '1d5920f4b44b27a802bd77c4f0536f5a be5cab0695415d9363d18ad1345c73eb)',
            ]
        ];
    }



    /**
     * @dataProvider complexQueryProvider
     *
     * @param $query
     * @param $hashed
     *
     * @throws \JsonException
     */
    public function testComplexQueryInsert($query, $hashed): void
    {
        $rule = new \App\Models\Rule();
        $rule->setQuery($query);
        $insert = $this->manticoreService->addRule($rule, null, true);
        self::assertEquals('Rule added', $insert['message']);
        $insertRuleId = $insert['data']->getId();

        $rule = $this->manticoreService->searchRuleExtended(1, 0, 0, 'desc', $insertRuleId);

        self::assertGreaterThan(0, $rule['data'][0]->getId());
        /**
         * @var $newRule \App\Models\Rule
         */
        $newRule = $rule['data'][0];

        self::assertEquals($hashed, trim($newRule->getQuery()));
        self::assertEquals($query, $newRule->getTags()->getOwnQuery());
    }

    public static function complexQueryProvider(): array
    {
        //$query, $hashed
        return [
            [
                '(("living proof perfect hair" -\$UN -\$UL)|("living proof curl" -\$UN -\$UL)) -"RT Like"',
                '(("living proof perfect hair" -\$un -\$ul)|("living proof curl" -\$un -\$ul)) -"rt like"'
            ],
            [
                'qusery=("#ibmathlete") | ("49ers d/st" | "a.j. brown" | "a.j. green" | "aj dillon" | "aj green" | "aaron jones" | '.
                '"aaron rodgers" | "adam humphries" | "adam shaheen" | "adam thielen" | "adam trautman" | "adrian peterson" | "albert okwuegbunam" | '.
                '"albert wilson" | "aldrick rosas" | "alec ingold" | "alex armah" | "alex collins" | "alexander mattison" | "allen lazard" | '.
                '"allen robinson" | "alshon jeffery" | "alvin kamara" | "amari cooper" | "amari rodgers" | "ameer abdullah" | "amon-ra st" | '.
                '"amon-ra st. brown" | "andre roberts" | "andrew jacas" | "andy dalton" | "andy isabella" | "anthony firkser" | "anthony mcfarland" | '.
                '"anthony mcfarland jr." | "anthony miller" | "anthony schwartz" | "antonio brown" | "antonio callaway" | "antonio gandy-golden" | '.
                '"antonio gibson" | "arizona cardinals" | "atlanta falcons" | "auden tate" | "austin ekeler" | "austin hooper" | "austin seibert" | '.
                '"baker mayfield" | "baltimore ravens" | "baltimore ravens d/st" | "bears d/st" | "ben roethlisberger" | "benny snell" | '.
                '"benny snell jr." | "bills d/st" | "blake bell" | "blake jarwin" | "boston scott" | "brandin cooks" | "brandon aiyuk" | '.
                '"brandon mcmanus" | "braxton berrios" | "breshad perriman" | "brevin jordan" | "brian hill" | "broncos d/st" | "browns d/st" | '.
                '"bryan edwards" | "bryce love" | "brycen hopkins" | "buccaneers d/st" | "buffalo bills" | "buffalo bills d/st" | "byron pringle" | '.
                '"c.j. ham" | "c.j. uzomah" | "cj uzomah" | "cade johnson" | "cairo santos" | "calvin ridley" | "cam newton" | "cam sims" | '.
                '"cameron batson" | "cameron brate" | "cardinals d/st" | "carlos hyde" | "carolina panthers" | "carson wentz" | "cedrick wilson" | '.
                '"ceedee lamb" | "chad beebe" | "charlie woerner" | "chase claypool" | "chase edmonds" | "chase mclaughlin" | "chester rogers" | '.
                '"chicago bears" | "chiefs d/st" | "chris boswell" | "chris carson" | "chris conley" | "chris evans" | "chris godwin" | '.
                '"chris herndon" | "chris herndon iv" | "chris hogan" | "chris manhertz" | "chris naggar" | "chris rowland" | "chris thompson" | '.
                '"christian blake" | "christian kirk" | "christian mccaffrey" | "chuba hubbard" | "cincinnati bengals" | "cleveland browns" | '.
                '"cleveland browns d/st" | "clyde edwards-helaire" | "cody parkey" | "cole beasley" | "cole kmet" | "collin johnson" | "colts d/st" | '.
                '"cooper kupp" | "cordarrelle patterson" | "corey clement" | "corey davis" | "cornell powell" | "courtland sutton" | '.
                '"curtis samuel" | "d\'andre swift" | "d\'ernest johnson" | "d\'onta foreman" | "d\'wayne eskridge" | "d.j. moore" | '.
                '"d.k. metcalf" | "dernest johnson" | "dj chark jr." | "dak prescott" | "dallas cowboys" | "dallas goedert" | "dalton schultz" | '.
                '"dalvin cook" | "damien harris" | "damien williams" | "damiere byrd" | "dan arnold" | "dan bailey" | "daniel carlson" | '.
                '"daniel jones" | "danny amendola" | "dante pettis" | "dare ogunbowale" | "darius slayton" | "darnell mooney" | '.
                '"darrel williams" | "darrell daniels" | "darrell henderson" | "darrell henderson jr." | "darren fells" | "darren waller" | '.
                '"darrynton evans" | "darwin thompson" | "davante adams" | "david johnson" | "david montgomery" | "david moore" | "david njoku" | '.
                '"davis mills" | "dawson knox" | "dazz newsome" | "deandre hopkins" | "desean jackson" | "devante parker" | "devonta smith" | '.
                '"dede westbrook" | "dedrick mills" | "dee eskridge" | "deejay dallas" | "deebo samuel" | "demarcus robinson" | "demetric felton" | '.
                '"denver broncos" | "denver broncos d/st" | "denzel mims" | "deonte harris" | "derek carr" | "derrick henry" | "derrick henry" | '.
                '"deshaun watson" | "detroit lions" | "devin duvernay" | "devin funchess" | "devin singletary" | "devin smith" | "devine ozigbo" | '.
                '"devonta freeman" | "devontae booker" | "dez fitzpatrick" | "diontae johnson" | "dolphins d/st" | "donald parham" | '.
                '"donald parham jr." | "donovan peoples-jones" | "drew lock" | "drew sample" | "duke johnson jr." | "duke williams" | '.
                '"durham smythe" | "dustin hopkins" | "dwayne haskins" | "dyami brown" | "elijah mitchell" | "elijah moore" | "emmanuel sanders" | '.
                '"eno ben" | "eno benjamin" | "equanimeous st. brown" | "eric ebron" | "evan engram" | "evan mcpherson" | "ezekiel elliott" | '.
                '"falcons d/st" | "farrod green" | "foster moreau" | "frank darby" | "frank gore" | "freddie swain" | "gabriel davis" | '.
                '"gardner minshew ii" | "garrett gilbert" | "garrett griffin" | "gary brightwell" | "geoff swaim" | "george kittle" | '.
                '"gerald everett" | "geronimo allison" | "gerrid doaks" | "giants d/st" | "giovani bernard" | "golden tate" | "graham gano" | '.
                '"green bay packers" | "green bay packers d/st" | "greg joseph" | "greg ward" | "greg zuerlein" | "gunner olszewski" | '.
                '"gus edwards" | "harrison bryant" | "harrison butker" | "hayden hurst" | "henry ruggs" | "henry ruggs iii" | '.
                '"houston texans" | "hunter bryant" | "hunter henry" | "hunter long" | "hunter renfrow" | "ian thomas" | '.
                '"ihmir smith-marsette" | "indianapolis colts" | "indianapolis colts d/st" | "irv smith" | "irv smith jr." | '.
                '"isaiah coulter" | "isaiah mckenzie" | "ito smith" | "j.d. mckissic" | "j.j. arcega-whiteside" | "j.j. taylor" | '.
                '"j.k. dobbins" | "jd mckissic" | "jj taylor" | "jk dobbins" | "ja\'marr chase" | "jamarr chase" | "jamycal hasty" | '.
                '"jace sternberger" | "jack doyle" | "jacksonville jaguars" | "jacob eason" | "jacob harris" | "jacob hollister" | '.
                '"jacoby brissett" | "jaelon darden" | "jake elliott" | "jake funk" | "jake kumerow" | "jakeem grant sr." | "jakobi meyers" | '.
                '"jalen guyton" | "jalen hurd" | "jalen hurts" | "jalen reagor" | "jalen richard" | "jamaal williams" | "jameis winston" | '.
                '"james conner" | "james o\'shaughnessy" | "james oshaughnessy" | "james robinson" | "james washington" | "james white" | '.
                '"jamison crowder" | "jared cook" | "jared goff" | "jaret patterson" | "jarvis landry" | "jason myers" | "jason sanders" | '.
                '"jauan jennings" | "javian hawkins" | "javon wims" | "javonte williams" | "jaylen samuels" | "jaylen waddle" | '.
                '"jeff smith" | "jeff wilson" | "jeff wilson jr." | "jeremy mcnichols" | "jerick mckinnon" | "jermar jefferson" | '.
                '"jerry jeudy" | "jets d/st" | "jimmy garoppolo" | "jimmy graham" | "joe burrow" | "joe mixon" | "joey slye" | '.
                '"john brown" | "john hightower" | "john ross" | "johnny mundt" | "jonathan taylor" | "jonathan ward" | "jonnu smith" | '.
                '"jordan akins" | "jordan howard" | "jordan love" | "jordan wilkins" | "josh allen" | "josh hill" | "josh jacobs" | '.
                '"josh lambo" | "josh oliver" | "josh palmer" | "josh reynolds" | "joshua kelley" | "juju smith-schuster" | "julio jones" | '.
                '"justice hill" | "justin fields" | "justin herbert" | "justin jackson" | "justin jefferson" | "justin tucker" | '.
                '"justin watson" | "juwan johnson" | "k.j. hamler" | "k.j. osborn" | "kj hamler" | "ka\'imi fairbairn" | "kadarius toney" | '.
                '"kahale warring" | "kalen ballage" | "kalif raymond" | "kansas city chiefs" | "kareem hunt" | "ke\'shawn vaughn" | '.
                '"keshawn vaughn" | "keelan cole" | "keelan cole sr." | "keenan allen" | "keith smith" | "keke coutee" | "kelvin harmon" | '.
                '"kendrick bourne" | "kene nwangwu" | "kenneth gainwell" | "kenny golladay" | "kenny stills" | "kenny yeboah" | '.
                '"kenyan drake" | "kerryon johnson" | "kevin white" | "khalil herbert" | "kirk cousins" | "kyle juszczyk" | "kyle pitts" | '.
                '"kyle rudolph" | "kylen granson" | "kyler murray" | "kylin hill" | "la\'mical perine" | "lamical perine" | '.
                '"lamar jackson" | "lamar miller" | "larry fitzgerald" | "larry rountree" | "larry rountree iii" | "las vegas raiders" | '.
                '"latavius murray" | "laviska shenault" | "laviska shenault jr." | "le\'veon bell" | "lesean mccoy" | "lee smith" | '.
                '"leonard fournette" | "lil\'jordan humphrey" | "liljordan humphrey" | "logan thomas" | "los angeles chargers" | '.
                '"los angeles rams" | "luke farrell" | "mac jones" | "mack hollins" | "malcolm brown" | "malik taylor" | "marcus mariota" | '.
                '"mark andrews" | "mark ingram" | "mark ingram ii" | "marlon mack" | "marquez callaway" | "marquez valdes-scantling" | '.
                '"marquise brown" | "marquise goodwin" | "marvin jones jr." | "mason crosby" | "matt ammendola" | "matt breida" | "matt gay" '.
                '| "matt prater" | "matt ryan" | "matthew stafford" | "maxx williams" | "mecole hardman" | "melvin gordon" | '.
                '"miami dolphins" | "michael badgley" | "michael burton" | "michael carter" | "michael gallup" | "michael pittman" | '.
                '"michael pittman jr." | "michael thomas" | "mike boone" | "mike davis" | "mike evans" | "mike gesicki" | "mike strachan" | '.
                '"mike thomas" | "mike williams" | "miles boykin" | "miles sanders" | "minnesota vikings" | "mitchell trubisky" | '.
                '"mo alie-cox" | "mohamed sanu" | "myles gaskin" | "n\'keal harry" | "nkeal harry" | "najee harris" | "nelson agholor" | '.
                '"new england patriots" | "new england patriots d/st" | "new orleans saints" | "new york giants" | "new york jets" | '.
                '"nick boyle" | "nick chubb" | "nick folk" | "nick vannett" | "nico collins" | "noah fant" | "noah gray" | "nyheim hines" | '.
                '"o.j. howard" | "oj howard" | "odell beckham jr." | "olamide zaccheaus" | "otis anderson jr." | "packers d/st" | '.
                '"parris campbell" | "pat freiermuth" | "patrick laird" | "patrick mahomes" | "patrick ricard" | "patriots d/st" | '.
                '"peyton barber" | "pharaoh brown" | "philadelphia eagles" | "phillip dorsett" | "phillip dorsett ii" | "phillip lindsay" | '.
                '"phillip walker" | "pittsburgh steelers" | "pittsburgh steelers d/st" | "pooka williams jr." | "preston williams" | '.
                '"qadree ollison" | "quez watkins" | "quinn nordin" | "quintez cephus" | "raheem mostert" | "rams d/st" | "randall cobb" | '.
                '"randy bullock" | "rashaad penny" | "rashard higgins" | "rashod bateman" | "ravens d/st" | "ray-ray mccloud" | '.
                '"raymond calais" | "rex burkhead" | "rhamondre stevenson" | "richard rodgers" | "richie james" | "richie james jr." | '.
                '"rob gronkowski" | "robbie gould" | "robby anderson" | "robert tonyan" | "robert woods" | "rodney adams" | '.
                '"rodrigo blankenship" | "ronald jones" | "ronald jones ii" | "rondale moore" | "ross dwelley" | "royce freeman" | '.
                '"russell gage" | "russell wilson" | "ryan fitzpatrick" | "ryan griffin" | "ryan santoso" | "ryan succop" | '.
                '"ryan tannehill" | "sage surratt" | "saints d/st" | "salvon ahmed" | "sam darnold" | "sam ficken" | "sam sloman" | '.
                '"samaje perine" | "sammy watkins" | "san francisco 49ers" | "san francisco 49ers d/st" | "saquon barkley" | '.
                '"scott miller" | "scotty miller" | "seattle seahawks" | "seth williams" | "shi smith" | "simi fehoko" | "sony michel" | '.
                '"steelers d/st" | "stefon diggs" | "stephen gostkowski" | "sterling shepard" | "steven sims jr." | "stevie scott iii" | '.
                '"t.j. hockenson" | "t.j. yeldon" | "t.y. hilton" | "ty hilton" | "tajae sharpe" | "tampa bay buccaneers" | '.
                '"tampa bay buccaneers d/st" | "tarik cohen" | "taylor heinicke" | "taysom hill" | "teddy bridgewater" | "tee higgins" | '.
                '"tennessee titans" | "terrace marshall" | "terrace marshall jr." | "terry mclaurin" | "tevin coleman" | "theo riddick" | '.
                '"tim patrick" | "tim tebow" | "titans d/st" | "todd gurley ii" | "tom brady" | "tommy sweeney" | "tommy tremble" | '.
                '"tony jones" | "tony jones jr." | "tony pollard" | "travis etienne" | "travis etienne jr." | "travis fulgham" | '.
                '"travis homer" | "travis kelce" | "trayveon williams" | "tre\' mckitty" | "tre\'quan smith" | "trequan smith" | '.
                '"trent sherfield" | "trenton cannon" | "trevor lawrence" | "trey burton" | "trey lance" | "trey ragas" | "trey sermon" | '.
                '"tua tagovailoa" | "tucker mccann" | "tutu atwell" | "ty johnson" | "ty montgomery" | "ty\'son williams" | '.
                '"tyson williams" | "tylan wallace" | "tyler bass" | "tyler boyd" | "tyler conklin" | "tyler eifert" | "tyler higbee" | '.
                '"tyler johnson" | "tyler kroft" | "tyler lockett" | "tyreek hill" | "tyrell williams" | "tyrod taylor" | '.
                '"tyron johnson" | "van jefferson" | "victor bolden jr." | "vikings d/st" | "wft d/st" | "washington football team" | '.
                '"washington football team d/st" | "wayne gallman" | "wil lutz" | "will dissly" | "will fuller v" | "willie snead iv" | '.
                '"xavier jones" | "younghoe koo" | "zach ertz" | "zach pascal" | "zach wilson" | "zack moss" | "zane gonzalez" | '.
                '"zay jones") ("#ibm" | ibm) | ("#watson" | watson) ("#ibm" | "\\@ibm" | ibm) ("#espn" | "#fantasyfootball" | '.
                '"\\@espn" | espn | fantasy | football | trade | trade)',
                'qusery=("#ibmathlete") | ("49ers d/st" | "a.j. brown" | "a.j. green" | "aj dillon" | "aj green" | "aaron jones" | '.
                '"aaron rodgers" | "adam humphries" | "adam shaheen" | "adam thielen" | "adam trautman" | "adrian peterson" | "albert okwuegbunam" | '.
                '"albert wilson" | "aldrick rosas" | "alec ingold" | "alex armah" | "alex collins" | "alexander mattison" | "allen lazard" | '.
                '"allen robinson" | "alshon jeffery" | "alvin kamara" | "amari cooper" | "amari rodgers" | "ameer abdullah" | "amon-ra st" | '.
                '"amon-ra st. brown" | "andre roberts" | "andrew jacas" | "andy dalton" | "andy isabella" | "anthony firkser" | "anthony mcfarland" | '.
                '"anthony mcfarland jr." | "anthony miller" | "anthony schwartz" | "antonio brown" | "antonio callaway" | "antonio gandy-golden" | '.
                '"antonio gibson" | "arizona cardinals" | "atlanta falcons" | "auden tate" | "austin ekeler" | "austin hooper" | "austin seibert" | '.
                '"baker mayfield" | "baltimore ravens" | "baltimore ravens d/st" | "bears d/st" | "ben roethlisberger" | "benny snell" | '.
                '"benny snell jr." | "bills d/st" | "blake bell" | "blake jarwin" | "boston scott" | "brandin cooks" | "brandon aiyuk" | '.
                '"brandon mcmanus" | "braxton berrios" | "breshad perriman" | "brevin jordan" | "brian hill" | "broncos d/st" | "browns d/st" | '.
                '"bryan edwards" | "bryce love" | "brycen hopkins" | "buccaneers d/st" | "buffalo bills" | "buffalo bills d/st" | "byron pringle" | '.
                '"c.j. ham" | "c.j. uzomah" | "cj uzomah" | "cade johnson" | "cairo santos" | "calvin ridley" | "cam newton" | "cam sims" | '.
                '"cameron batson" | "cameron brate" | "cardinals d/st" | "carlos hyde" | "carolina panthers" | "carson wentz" | "cedrick wilson" | '.
                '"ceedee lamb" | "chad beebe" | "charlie woerner" | "chase claypool" | "chase edmonds" | "chase mclaughlin" | "chester rogers" | '.
                '"chicago bears" | "chiefs d/st" | "chris boswell" | "chris carson" | "chris conley" | "chris evans" | "chris godwin" | '.
                '"chris herndon" | "chris herndon iv" | "chris hogan" | "chris manhertz" | "chris naggar" | "chris rowland" | "chris thompson" | '.
                '"christian blake" | "christian kirk" | "christian mccaffrey" | "chuba hubbard" | "cincinnati bengals" | "cleveland browns" | '.
                '"cleveland browns d/st" | "clyde edwards-helaire" | "cody parkey" | "cole beasley" | "cole kmet" | "collin johnson" | "colts d/st" | '.
                '"cooper kupp" | "cordarrelle patterson" | "corey clement" | "corey davis" | "cornell powell" | "courtland sutton" | '.
                '"curtis samuel" | "d\'andre swift" | "d\'ernest johnson" | "d\'onta foreman" | "d\'wayne eskridge" | "d.j. moore" | '.
                '"d.k. metcalf" | "dernest johnson" | "dj chark jr." | "dak prescott" | "dallas cowboys" | "dallas goedert" | "dalton schultz" | '.
                '"dalvin cook" | "damien harris" | "damien williams" | "damiere byrd" | "dan arnold" | "dan bailey" | "daniel carlson" | '.
                '"daniel jones" | "danny amendola" | "dante pettis" | "dare ogunbowale" | "darius slayton" | "darnell mooney" | '.
                '"darrel williams" | "darrell daniels" | "darrell henderson" | "darrell henderson jr." | "darren fells" | "darren waller" | '.
                '"darrynton evans" | "darwin thompson" | "davante adams" | "david johnson" | "david montgomery" | "david moore" | "david njoku" | '.
                '"davis mills" | "dawson knox" | "dazz newsome" | "deandre hopkins" | "desean jackson" | "devante parker" | "devonta smith" | '.
                '"dede westbrook" | "dedrick mills" | "dee eskridge" | "deejay dallas" | "deebo samuel" | "demarcus robinson" | "demetric felton" | '.
                '"denver broncos" | "denver broncos d/st" | "denzel mims" | "deonte harris" | "derek carr" | "derrick henry" | "derrick henry" | '.
                '"deshaun watson" | "detroit lions" | "devin duvernay" | "devin funchess" | "devin singletary" | "devin smith" | "devine ozigbo" | '.
                '"devonta freeman" | "devontae booker" | "dez fitzpatrick" | "diontae johnson" | "dolphins d/st" | "donald parham" | '.
                '"donald parham jr." | "donovan peoples-jones" | "drew lock" | "drew sample" | "duke johnson jr." | "duke williams" | '.
                '"durham smythe" | "dustin hopkins" | "dwayne haskins" | "dyami brown" | "elijah mitchell" | "elijah moore" | "emmanuel sanders" | '.
                '"eno ben" | "eno benjamin" | "equanimeous st. brown" | "eric ebron" | "evan engram" | "evan mcpherson" | "ezekiel elliott" | '.
                '"falcons d/st" | "farrod green" | "foster moreau" | "frank darby" | "frank gore" | "freddie swain" | "gabriel davis" | '.
                '"gardner minshew ii" | "garrett gilbert" | "garrett griffin" | "gary brightwell" | "geoff swaim" | "george kittle" | '.
                '"gerald everett" | "geronimo allison" | "gerrid doaks" | "giants d/st" | "giovani bernard" | "golden tate" | "graham gano" | '.
                '"green bay packers" | "green bay packers d/st" | "greg joseph" | "greg ward" | "greg zuerlein" | "gunner olszewski" | '.
                '"gus edwards" | "harrison bryant" | "harrison butker" | "hayden hurst" | "henry ruggs" | "henry ruggs iii" | '.
                '"houston texans" | "hunter bryant" | "hunter henry" | "hunter long" | "hunter renfrow" | "ian thomas" | '.
                '"ihmir smith-marsette" | "indianapolis colts" | "indianapolis colts d/st" | "irv smith" | "irv smith jr." | '.
                '"isaiah coulter" | "isaiah mckenzie" | "ito smith" | "j.d. mckissic" | "j.j. arcega-whiteside" | "j.j. taylor" | '.
                '"j.k. dobbins" | "jd mckissic" | "jj taylor" | "jk dobbins" | "ja\'marr chase" | "jamarr chase" | "jamycal hasty" | '.
                '"jace sternberger" | "jack doyle" | "jacksonville jaguars" | "jacob eason" | "jacob harris" | "jacob hollister" | '.
                '"jacoby brissett" | "jaelon darden" | "jake elliott" | "jake funk" | "jake kumerow" | "jakeem grant sr." | "jakobi meyers" | '.
                '"jalen guyton" | "jalen hurd" | "jalen hurts" | "jalen reagor" | "jalen richard" | "jamaal williams" | "jameis winston" | '.
                '"james conner" | "james o\'shaughnessy" | "james oshaughnessy" | "james robinson" | "james washington" | "james white" | '.
                '"jamison crowder" | "jared cook" | "jared goff" | "jaret patterson" | "jarvis landry" | "jason myers" | "jason sanders" | '.
                '"jauan jennings" | "javian hawkins" | "javon wims" | "javonte williams" | "jaylen samuels" | "jaylen waddle" | '.
                '"jeff smith" | "jeff wilson" | "jeff wilson jr." | "jeremy mcnichols" | "jerick mckinnon" | "jermar jefferson" | '.
                '"jerry jeudy" | "jets d/st" | "jimmy garoppolo" | "jimmy graham" | "joe burrow" | "joe mixon" | "joey slye" | '.
                '"john brown" | "john hightower" | "john ross" | "johnny mundt" | "jonathan taylor" | "jonathan ward" | "jonnu smith" | '.
                '"jordan akins" | "jordan howard" | "jordan love" | "jordan wilkins" | "josh allen" | "josh hill" | "josh jacobs" | '.
                '"josh lambo" | "josh oliver" | "josh palmer" | "josh reynolds" | "joshua kelley" | "juju smith-schuster" | "julio jones" | '.
                '"justice hill" | "justin fields" | "justin herbert" | "justin jackson" | "justin jefferson" | "justin tucker" | '.
                '"justin watson" | "juwan johnson" | "k.j. hamler" | "k.j. osborn" | "kj hamler" | "ka\'imi fairbairn" | "kadarius toney" | '.
                '"kahale warring" | "kalen ballage" | "kalif raymond" | "kansas city chiefs" | "kareem hunt" | "ke\'shawn vaughn" | '.
                '"keshawn vaughn" | "keelan cole" | "keelan cole sr." | "keenan allen" | "keith smith" | "keke coutee" | "kelvin harmon" | '.
                '"kendrick bourne" | "kene nwangwu" | "kenneth gainwell" | "kenny golladay" | "kenny stills" | "kenny yeboah" | '.
                '"kenyan drake" | "kerryon johnson" | "kevin white" | "khalil herbert" | "kirk cousins" | "kyle juszczyk" | "kyle pitts" | '.
                '"kyle rudolph" | "kylen granson" | "kyler murray" | "kylin hill" | "la\'mical perine" | "lamical perine" | '.
                '"lamar jackson" | "lamar miller" | "larry fitzgerald" | "larry rountree" | "larry rountree iii" | "las vegas raiders" | '.
                '"latavius murray" | "laviska shenault" | "laviska shenault jr." | "le\'veon bell" | "lesean mccoy" | "lee smith" | '.
                '"leonard fournette" | "lil\'jordan humphrey" | "liljordan humphrey" | "logan thomas" | "los angeles chargers" | '.
                '"los angeles rams" | "luke farrell" | "mac jones" | "mack hollins" | "malcolm brown" | "malik taylor" | "marcus mariota" | '.
                '"mark andrews" | "mark ingram" | "mark ingram ii" | "marlon mack" | "marquez callaway" | "marquez valdes-scantling" | '.
                '"marquise brown" | "marquise goodwin" | "marvin jones jr." | "mason crosby" | "matt ammendola" | "matt breida" | "matt gay" '.
                '| "matt prater" | "matt ryan" | "matthew stafford" | "maxx williams" | "mecole hardman" | "melvin gordon" | '.
                '"miami dolphins" | "michael badgley" | "michael burton" | "michael carter" | "michael gallup" | "michael pittman" | '.
                '"michael pittman jr." | "michael thomas" | "mike boone" | "mike davis" | "mike evans" | "mike gesicki" | "mike strachan" | '.
                '"mike thomas" | "mike williams" | "miles boykin" | "miles sanders" | "minnesota vikings" | "mitchell trubisky" | '.
                '"mo alie-cox" | "mohamed sanu" | "myles gaskin" | "n\'keal harry" | "nkeal harry" | "najee harris" | "nelson agholor" | '.
                '"new england patriots" | "new england patriots d/st" | "new orleans saints" | "new york giants" | "new york jets" | '.
                '"nick boyle" | "nick chubb" | "nick folk" | "nick vannett" | "nico collins" | "noah fant" | "noah gray" | "nyheim hines" | '.
                '"o.j. howard" | "oj howard" | "odell beckham jr." | "olamide zaccheaus" | "otis anderson jr." | "packers d/st" | '.
                '"parris campbell" | "pat freiermuth" | "patrick laird" | "patrick mahomes" | "patrick ricard" | "patriots d/st" | '.
                '"peyton barber" | "pharaoh brown" | "philadelphia eagles" | "phillip dorsett" | "phillip dorsett ii" | "phillip lindsay" | '.
                '"phillip walker" | "pittsburgh steelers" | "pittsburgh steelers d/st" | "pooka williams jr." | "preston williams" | '.
                '"qadree ollison" | "quez watkins" | "quinn nordin" | "quintez cephus" | "raheem mostert" | "rams d/st" | "randall cobb" | '.
                '"randy bullock" | "rashaad penny" | "rashard higgins" | "rashod bateman" | "ravens d/st" | "ray-ray mccloud" | '.
                '"raymond calais" | "rex burkhead" | "rhamondre stevenson" | "richard rodgers" | "richie james" | "richie james jr." | '.
                '"rob gronkowski" | "robbie gould" | "robby anderson" | "robert tonyan" | "robert woods" | "rodney adams" | '.
                '"rodrigo blankenship" | "ronald jones" | "ronald jones ii" | "rondale moore" | "ross dwelley" | "royce freeman" | '.
                '"russell gage" | "russell wilson" | "ryan fitzpatrick" | "ryan griffin" | "ryan santoso" | "ryan succop" | '.
                '"ryan tannehill" | "sage surratt" | "saints d/st" | "salvon ahmed" | "sam darnold" | "sam ficken" | "sam sloman" | '.
                '"samaje perine" | "sammy watkins" | "san francisco 49ers" | "san francisco 49ers d/st" | "saquon barkley" | '.
                '"scott miller" | "scotty miller" | "seattle seahawks" | "seth williams" | "shi smith" | "simi fehoko" | "sony michel" | '.
                '"steelers d/st" | "stefon diggs" | "stephen gostkowski" | "sterling shepard" | "steven sims jr." | "stevie scott iii" | '.
                '"t.j. hockenson" | "t.j. yeldon" | "t.y. hilton" | "ty hilton" | "tajae sharpe" | "tampa bay buccaneers" | '.
                '"tampa bay buccaneers d/st" | "tarik cohen" | "taylor heinicke" | "taysom hill" | "teddy bridgewater" | "tee higgins" | '.
                '"tennessee titans" | "terrace marshall" | "terrace marshall jr." | "terry mclaurin" | "tevin coleman" | "theo riddick" | '.
                '"tim patrick" | "tim tebow" | "titans d/st" | "todd gurley ii" | "tom brady" | "tommy sweeney" | "tommy tremble" | '.
                '"tony jones" | "tony jones jr." | "tony pollard" | "travis etienne" | "travis etienne jr." | "travis fulgham" | '.
                '"travis homer" | "travis kelce" | "trayveon williams" | "tre\' mckitty" | "tre\'quan smith" | "trequan smith" | '.
                '"trent sherfield" | "trenton cannon" | "trevor lawrence" | "trey burton" | "trey lance" | "trey ragas" | "trey sermon" | '.
                '"tua tagovailoa" | "tucker mccann" | "tutu atwell" | "ty johnson" | "ty montgomery" | "ty\'son williams" | '.
                '"tyson williams" | "tylan wallace" | "tyler bass" | "tyler boyd" | "tyler conklin" | "tyler eifert" | "tyler higbee" | '.
                '"tyler johnson" | "tyler kroft" | "tyler lockett" | "tyreek hill" | "tyrell williams" | "tyrod taylor" | '.
                '"tyron johnson" | "van jefferson" | "victor bolden jr." | "vikings d/st" | "wft d/st" | "washington football team" | '.
                '"washington football team d/st" | "wayne gallman" | "wil lutz" | "will dissly" | "will fuller v" | "willie snead iv" | '.
                '"xavier jones" | "younghoe koo" | "zach ertz" | "zach pascal" | "zach wilson" | "zack moss" | "zane gonzalez" | '.
                '"zay jones") ("#ibm" | ibm) | ("#watson" | watson) ("#ibm" | "\\@ibm" | ibm) ("#espn" | "#fantasyfootball" | '.
                '"\\@espn" | espn | fantasy | football | trade | trade)'
            ]
        ];
    }

    /**
     * @dataProvider searchProvider
     *
     * @param $query
     * @param $weakQuery
     * @param $tag
     * @param $weakTags
     * @param $filters
     * @param $externalQuery
     * @param $resultsCount
     */

    public function testSearchExtended($query, $weakQuery, $tag, $weakTags, $filters, $externalQuery, $resultsCount)
    {
        unset($_REQUEST['query']);
        if ($query !== null) {
            $_REQUEST['query'] = $query;
        }
        $rules = $this->manticoreService
            ->searchRuleExtended(50, 0, 0, 'desc', null, $query, $weakQuery, $tag, $weakTags, $filters, $externalQuery);


        self::assertCount($resultsCount, $rules['data']);
    }

    public static function searchProvider(): array
    {
        //$query, $weakQuery, $tag, $weakTags, $filters, $external, $resultsCount
        return [
            ['hello', true, null, null, null, null, 5],
            ['pizza', false, 'tag / ag', null, null, null, 1],
            ['pizza', false, ['tag / ag'], null, null, null, 1],
            ['manti', false, ['{"a":"b"}'], null, null, null, 1],
            ['manti', false, '{"a":"b"}', null, null, null, 1],
            ['svnba', false, '\\x32', null, null, null, 1],
            ['svnbac', false, null, null, null, '\\x32', 1],
            ['svnbac', false, null, null, null, 'query=(\"andy penn\")&filter_language=en', 1],
            [
                'svnbac', false, null, null, null,
                'query=(achmea| avero| centraalbeheer| centraal beheer| defriesland| \"de friesland\"| eurocross'.
                '| fbto| independer| inshared| interpolis| syntrus| roadguard| zilverenkruis| \"zilveren kruis\")&dn=youtube.com',
                1,
            ],
            ['svnba', false, '\\x32(q)', null, null, null, 1],
            ['svnbac', false, null, null, null, '\\x32(q)', 1],
            ['testtext', false, null, null, null, 'externalQuery', 1],
            ['query', true, null, null, null, null, 8],
            [null, false, null, null, 'json.lang="en"', null, 2],
            ['', false, null, null, 'json.lang="en"', null, 1],
            ['"non empty query"', false, null, null, 'json.lang="en"', null, 1],
            ['testText', false, null, null, null, null, 1],
            ['@(merged, merged2) https://mail.google.com/ -manticoresearch.com', false, null, null, null, null, 1],
            [
                '@merged manticoresearch.com | https://mail.google.com/ -https://app.tmetric.com',
                false,
                null,
                null,
                null,
                null,
                1,
            ],
            ['@merged manticoresearch.com | https://mail.google.com/', false, null, null, null, null, 1],
            ['generalquery @text querytofind @(merged, merged2) https://mail.google.com/@abc', false, null, null, null, null, 1],
            ['@merged manticoresearch.com | https://mail.google.com/', true, null, null, null, null, 3],
            ['@merged https://manual.manticoresearch.com/Introduction#JSON-over-HTTP', false, null, null, null, null, 1],
            [
                ['testText', '"non empty query"', '@(merged, merged2) https://mail.google.com/ -manticoresearch.com'],
                false,
                null,
                null,
                null,
                null,
                3,
            ],
            [['(iphone|iphone5)'], false, ['auto_tests_tag_1'], null, null, null, 1],
            ['(("beard" NEAR/4 ("wash" | "coloring")))', false, 'tag', null, null, '(("beard" NEAR/4 ("wash" | "Coloring")))', 1],
            ['Manticore AND Streams OR hello MAYBE wOrld', false, null, null, null, 'Manticore AND Streams OR hello MAYBE wOrld', 1],
            ["Trello -world Nello !wash", false, null, null, null, 'externalQuery', 1],
            ['"the world is a wonderful place"/3', false, null, null, null, '"the world is a wonderful place"/3', 1],
            ['"hello World"~10', false, null, null, null, '"hello World"~10', 1],
            ['"exact * phrasE * * for terms"', false, null, null, null, '"exact * phrasE * * for terms"', 1],
            [
                '(bag of words) << "Exact phrase" << red|grEEn|blue', false, null, null, null,
                '(bag of words) << "Exact phrase" << red|grEEn|blue', 1,
            ],
            ['^Hello World$', false, null, null, null, '^Hello World$', 1],
            ['boosted^1.234 boostedfieldend$^1.234', false, null, null, null, 'boosted^1.234 boostedfieldend$^1.234', 1],
            ['hello NEAR/3 World NEAR/4 "my test"', false, null, null, null, 'hello NEAR/3 World NEAR/4 "my test"', 1],
            ['Church NOTNEAR/3 street', false, null, null, null, 'Church NOTNEAR/3 street', 1],
            ['all SENTENCE words SENTENCE "in one sentence"', false, null, null, null, 'all SENTENCE words SENTENCE "in one sentence"', 1],
            ['"Bill Gates" PARAGRAPH "Steve Jobs"', false, null, null, null, '"Bill Gates" PARAGRAPH "Steve Jobs"', 1],
            ['ZONE:th hello people', false, null, null, null, 'ZONE:th hello people', 1],
            ['ZONESPAN:th Works fine', false, null, null, null, 'ZONESPAN:th Works fine', 1],

            //$query, $weakQuery, $tag, $weakTags, $filters, $external, $resultsCount
        ];
    }


    /**
     * @test
     */
    public function checkDeduplication()
    {
        $this->manticoreService->truncateRules();

        $rule = new \App\Models\Rule();
        $rule->setQuery('123 -d');
        $insertOne = $this->manticoreService->addRule($rule, null, true);
        self::assertEquals('Rule added', $insertOne['message']);

        $insertSecond = $this->manticoreService->addRule($rule, null, true);
        self::assertEquals([
            'message' => 'This rule already added',
            'data' => $insertOne['data'],
        ], $insertSecond);
    }


    /**
     * @test
     */

    public function substituteVariablesOnAdd()
    {
        Variable::truncate();

        $user          = User::find(2);
        $user->process = 1;
        $user->save();
        Auth::setUser($user);

        $variableForQuery   = Variable::factory()->create(['stream_id' => $user->process]);
        $filter             = 'json.lang !="en"';
        $variableForFilters = Variable::factory()->create(['stream_id' => $user->process, 'text' => $filter]);

        $rule = new Rule();
        $rule->setQuery("Some query {{{$variableForQuery->name}}}");
        $rule->setFilters("json.tags != '42' AND {{{$variableForFilters->name}}}");

        $insert = $this->manticoreService->addRule($rule, null, false);

        $insertedRule = $this->manticoreService->getRuleById($insert['data']);


        self::assertEquals('Rule added', $insert['message']);
        self::assertSame($this->manticoreService->getStatusCode(), 200);
        self::assertStringContainsString('{{'.$variableForQuery->name.'}}', $insertedRule->getQuery());
        self::assertStringContainsString('{{'.$variableForFilters->name.'}}', $insertedRule->getFilters());

        self::assertSame($rule->getQueryWithVariableSubstituted(), $insertedRule->getQueryWithVariableSubstituted());
        self::assertSame($rule->getFiltersWithVariableSubstituted(), $insertedRule->getFiltersWithVariableSubstituted());
    }


    /**
     * @test
     */


    public function substituteTwoVariablesOnAdd()
    {
        Variable::truncate();
        $user = User::find(2);
        Auth::setUser($user);
        $this->manticoreService->truncateRules();
        VariablesService::getInstance()->clean();

        $variableForQuery1 = Variable::factory()->create(['stream_id' => $user->process]);
        $variableForQuery2 = Variable::factory()->create(['stream_id' => $user->process]);


        $filter1 = 'json.lang !="en"';
        $filter2 = 'json.lang !="ru"';

        $variableForFilters1 = Variable::factory()->create(['stream_id' => $user->process, 'text' => $filter1]);
        $variableForFilters2 = Variable::factory()->create(['stream_id' => $user->process, 'text' => $filter2]);

        $rule = new Rule();

        $rule->setQuery("Some query {{{$variableForQuery1->name}}} {{{$variableForQuery2->name}}}");
        $rule->setFilters("json.tags != '42' AND {{{$variableForFilters1->name}}} AND {{{$variableForFilters2->name}}}");

        $insert = $this->manticoreService->addRule($rule, null, false);

        self::assertEquals('Rule added', $insert['message']);


        $insertedRule = $this->manticoreService->getRuleById($insert['data']);


        self::assertStringContainsString('{{'.$variableForQuery1->name.'}}', $insertedRule->getQuery());
        self::assertStringContainsString('{{'.$variableForQuery2->name.'}}', $insertedRule->getQuery());

        self::assertStringContainsString('{{'.$variableForFilters1->name.'}}', $insertedRule->getFilters());
        self::assertStringContainsString('{{'.$variableForFilters2->name.'}}', $insertedRule->getFilters());

        self::assertSame($rule->getQueryWithVariableSubstituted(), $insertedRule->getQueryWithVariableSubstituted());
        self::assertSame($rule->getFiltersWithVariableSubstituted(), $insertedRule->getFiltersWithVariableSubstituted());
    }

    public function testSearchVariable()
    {
        Variable::truncate();

        $user          = User::find(2);
        $user->process = 1;
        $user->save();
        Auth::setUser($user);
        $this->manticoreService->truncateRules();

        $variableForQuery   = Variable::factory()->create(['stream_id' => $user->process]);
        $filter             = 'json.lang !="en"';
        $variableForFilters = Variable::factory()->create(['stream_id' => $user->process, 'text' => $filter]);

        $rule = new Rule();
        $rule->setQuery("Some query");
        $rule->setFilters("json.tags != '42'");
        $this->manticoreService->addRule($rule, null, false);

        $rule = new Rule();
        $rule->setQuery("Some query {{{$variableForQuery->name}}}");
        $rule->setFilters("json.tags != '42' AND {{{$variableForFilters->name}}}");

        $this->manticoreService->addRule($rule, null, false);

        $rules = $this->manticoreService
            ->searchRuleExtended(50, 0, 0, 'desc', null, null, false, null, null, null, null, $variableForQuery->name);

        self::assertCount(1, $rules['data']);

        $rules = $this->manticoreService
            ->searchRuleExtended(50, 0, 0, 'desc', null, null, false, null, null, null, null, $variableForFilters->name);

        self::assertCount(1, $rules['data']);
    }


    /**
     * @test
     */
    public function updateRuleVariables(): void
    {
        $this->manticoreService->truncateRules();
        Variable::truncate();
        $user = User::find(2);
        Auth::setUser($user);

        VariablesService::getInstance()->clean();

        $variableBefore1 = Variable::factory()->create(['stream_id' => $user->process]);
        $variableBefore2 = Variable::factory()->create(['stream_id' => $user->process]);
        $variableBefore3 = Variable::factory()->create(['stream_id' => $user->process]);


        $rule = new Rule();
        $rule->setQuery("Some query {{{$variableBefore1->name}}} {{{$variableBefore2->name}}} {{{$variableBefore3->name}}}");
        $insert = $this->manticoreService->addRule($rule, null, false);
        self::assertEquals('Rule added', $insert['message']);
        $insertedRule = $this->manticoreService->getRuleById($insert['data']);

        self::assertStringContainsString('{{'.$variableBefore1->name.'}}', $insertedRule->getQuery());
        self::assertSame($rule->getQueryWithVariableSubstituted(), $insertedRule->getQueryWithVariableSubstituted());

        $variableAfter = $variableBefore1->replicate();

        $newVariable         = "my edited variable";
        $variableAfter->text = $newVariable;

        $this->curl->shouldReceive('post')->andReturn(['status' => CurlService::STATUS_SUCCESS, 'result' => []]);
        $result = $this->manticoreService->updateRuleVariables($variableBefore1, $variableAfter);
        self::assertTrue($result);

        VariablesService::getInstance()->clean();
        $insertedRule = $this->manticoreService->getRuleById($insert['data']);

        self::assertStringContainsString('{{'.$variableBefore1->name.'}}', $insertedRule->getQuery());
        self::assertStringContainsString($newVariable, $insertedRule->getQueryWithVariableSubstituted());
    }

    /**
     * @test
     */

    public function deleteVariablesSuccess()
    {
        $this->manticoreService->truncateRules();
        Variable::truncate();
        $user = User::find(2);
        Auth::setUser($user);

        VariablesService::getInstance()->clean();

        $variable1 = Variable::factory()->create(['stream_id' => $user->process]);
        $variable2 = Variable::factory()->create(['stream_id' => $user->process]);
        $variable3 = Variable::factory()->create(['stream_id' => $user->process]);


        $rule = new Rule();
        $rule->setQuery("Some query {{{$variable1->name}}} {{{$variable2->name}}} {{{$variable3->name}}}");
        $rule->setFilters('json.tag = "{{'.$variable2->name.'}}"');
        $insert = $this->manticoreService->addRule($rule, null, false);
        self::assertEquals('Rule added', $insert['message']);


        $this->curl->shouldReceive('post')->andReturn(['status' => CurlService::STATUS_SUCCESS, 'result' => []]);
        $result = $this->manticoreService->removeRuleVariable($variable2);
        self::assertTrue($result);

        VariablesService::getInstance()->clean();
        $insertedRule = $this->manticoreService->getRuleById($insert['data']);

        self::assertStringNotContainsString('{{'.$variable2->name.'}}', $insertedRule->getQuery());
        self::assertSame('json.tag = ""', $insertedRule->getFiltersWithVariableSubstituted());

        $var = Variable::find($variable2->id);
        self::assertNull($var);
    }

    /**
     * @test
     */

    public function unsucsessDeleteVariableLeaveModel()
    {
        $this->manticoreService->truncateRules();
        Variable::truncate();
        $user = User::find(2);
        Auth::setUser($user);
        VariablesService::getInstance()->clean();


        $variable = Variable::factory()->create(['text' => 'json.tag = "myName"', 'stream_id' => $user->process]);


        $rule = new Rule();
        $rule->setFilters('json.tag = "123" and {{'.$variable->name.'}}');
        $insert = $this->manticoreService->addRule($rule, null, false);
        self::assertEquals('Rule added', $insert['message']);


        $this->curl->shouldReceive('post')->andReturn(['status' => CurlService::STATUS_SUCCESS, 'result' => []]);
        $result = $this->manticoreService->removeRuleVariable($variable);
        self::assertFalse($result);

        VariablesService::getInstance()->clean();
        $insertedRule = $this->manticoreService->getRuleById($insert['data']);

        self::assertSame('json.tag = "123" and '.$variable->text.'', $insertedRule->getFiltersWithVariableSubstituted());

        $var = Variable::find($variable->id);
        self::assertNotNull($var);
    }
}
