<?php

namespace Tests\Unit\Commands;

use App\Models\Processes;
use App\Services\Curl\CurlService;
use App\Services\StreamsService;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;
use Tests\Traits\AuthTrait;

/**
 * @group application
 */
class ProcessConfigmap extends TestCase
{
    use AuthTrait;

    /**
     * @test
     */

    public function createConfigMapForProcessingWithCustomLanguage(){
        $process = factory(Processes::class)->create();

        $this->instance(
            StreamsService::class,
            Mockery::mock(StreamsService::class, function (MockInterface $mock) use ($process){

                $mock->shouldReceive('setProcessId')
                    ->with($process->id)
                    ->times(1);

                $mock->shouldReceive('createConfigmap')
                    ->with("
         index_token_filter='blended.so:blended'
         charset_table='cjk'
         ngram_len='1'
         html_strip='1'
         index_sp='1'
         html_remove_elements='style, script'
         stopword_step='0' stopwords='/etc/manticoresearch/conf_mount/stopwords.txt' exceptions='/etc/manticoresearch/conf_mount/exceptions.txt'","", '
         the
         to
         i
         and
         a
         this
         you
         of
         it
         in
         that
         is
         s
         my
         t
         on
         for
         re
         but
         be
         was
         so
         rlpspecialstopword', '
         1 & 1 => 1&1
         1 + 1 => 1+1
         1+1 => 1+1
         1 and 1 => 1 and 1
         1C: => 1C:
         2.0 => 2.0
         24/7 => 24/7
         24x7 => 24x7
         2.5G => 2.5G
         501(c)(3) => 501(c)(3)
         501(c)3 => 501(c)(3)
         501(c)(4) => 501(c)(4)
         501(c)4 => 501(c)(4)
         509(a)(1) => 509(a)(1)
         509(a)1 => 509(a)(1)
         509(a)(2) => 509(a)(2)
         509(a)2 => 509(a)(2)
         509(a)(3) => 509(a)(3)
         509(a)3 => 509(a)(3)
         7 eleven => 7-Eleven
         7 Eleven => 7-Eleven
         A. => A.
         A Coruña => A Coruña
         A/E => A/E
         A/E/C => A/E/C
         AG => AG
         F. A. => F. A.
         Floor & Decor => FloorAndDecor
         Floor and Decor => FloorAndDecor
         fortress re => Fortress Re
         Zurich Re => Zurich Re')
                    ->times(1)
                    ->andReturn(['status' => CurlService::STATUS_SUCCESS, 'result' => []]);


                $mock->shouldReceive('getErrors')
                    ->times(1)
                    ->andReturn([]);
            })
        );


        $this->artisan('process:configmap '.$process->id)->assertExitCode(0);
    }



    /**
     * @test
     */

    public function createConfigMapForProcessingWithSimpleLanguage(){
        $process = factory(Processes::class)->state('simple')->create();

        $this->instance(
            StreamsService::class,
            Mockery::mock(StreamsService::class, function (MockInterface $mock) use ($process){

                $mock->shouldReceive('setProcessId')
                    ->with($process->id)
                    ->times(1);

                $mock->shouldReceive('createConfigmap')
                    ->with("morphology = 'icu_chinese' charset_table = 'cjk, non_cjk' stopwords = 'zh'", '', '', '')
                    ->times(1)
                    ->andReturn(['status' => CurlService::STATUS_SUCCESS, 'result' => []]);


                $mock->shouldReceive('getErrors')
                    ->times(1)
                    ->andReturn([]);
            })
        );


        $this->artisan('process:configmap '.$process->id)->assertExitCode(0);
    }
}
