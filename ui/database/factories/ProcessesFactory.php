<?php

namespace Database\Factories;

use App\Models\Destination;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Model>
 */
class ProcessesFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {

        $sources = Source::pluck('id')->toArray();
        $destinations = Destination::pluck('id')->toArray();

        return [
            'name' => $this->faker->word,
            'source_id' => $this->faker->randomElement($sources),
            'destination_id' => $this->faker->randomElement($destinations),
            'values' => serialize($this->getDefaultValues())
        ];

    }



    public function simple(): Factory
    {
        $values = $this->getDefaultValues();
        $values['user_request'] = [
            "attrs" => '[{"name":"whole_document","path":"whole_document","type":"json"}]',
            "output_docs" => "0010",
            "language" => "chinese",
            "min_threads" => "1",
            "max_threads" => "3",
            "max_batch_size" => "5000",
        ];

        return $this->state(function (array $attributes) use ($values) {
            return ['values' => serialize($values)];
        });
    }


    public function getDefaultValues(): array
    {
        return [
            "kafka" => [
                "inputHost" => "kafka-dev01.example.com:9092,kafka-dev02.example.com:9092,kafka-dev03.example.com:9092",
                "outputHost" => "kafka-dev01.example.com:9092,kafka-dev02.example.com:9092,kafka-dev03.example.com:9092",
                "inputTopic" => "reddit_pq_combined_stream_ms",
                "outputTopic" => "pquery.reddit.{username}.out",
                "groupName" => "pquery.reddit.{username}",
            ],
            "worker" => [
                "outputDocs" => "0011",
                "jsltEnabled" => false,
                "jsltConf" => "",
                "handlerRules" => "whole_document => json|title => title|body => text|url&&author_url&&permalink => url",
                "minThreads" => "1",
                "maxThreads" => "2",
                "maxBatchSize" => "5000",
            ],
            "manticore" => [
                "configAdditiveFields" => "json=json|text=title|text=text|url=url",
                "searchd" => [
                    "blacklist-mode" => 1,
                ],
            ],
            "user_request" => [
                "attrs" => '[{"name":"json","path":"whole_document","type":"json"},{"name":"title","path":"title","type":"string"},{"name":"text","path":"body","type":"string"},{"name":"url","path":"url&&author_url&&permalink","type":"url"}]',
                "output_docs" => "0011",
                "searchd_settings" => '{"blacklist-mode":1}',
                "language" => "custom",
                "min_threads" => "1",
                "max_threads" => "2",
                "max_batch_size" => "5000",
                "nlp_settings" => "
         index_token_filter='blended.so:blended'
         charset_table='cjk'
         ngram_len='1'
         html_strip='1'
         index_sp='1'
         html_remove_elements='style, script'
         stopword_step='0'",
                "stopwords" => "
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
         rlpspecialstopword",
                "exceptions" => "
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
         Zurich Re => Zurich Re",
            ],
        ];
    }
}

