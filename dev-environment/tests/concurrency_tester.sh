#!/bin/bash

#cd ../../ui || exit 1
#timeout 3 php artisan serve &

#for i in {1..5}
#do
  curl http://127.0.0.1:8000/api/manager/rules/add -X POST --cookie "XDEBUG_SESSION=XDEBUG_ECLIPSE"  -d '{"rule_text":"Stream 1","streamId":"1"}' -H "Authorization: Bearer 1Nc4OyONtmikIsfi" -H "Accept: application/json"  -H "Content-Type: application/json"
  curl http://127.0.0.1:8000/api/manager/rules/delete/1?streamId=1 --cookie "XDEBUG_SESSION=XDEBUG_ECLIPSE" -H "Authorization: Bearer 1Nc4OyONtmikIsfi" -H "Accept: application/json"  -H "Content-Type: application/json"
  curl http://127.0.0.1:8000/api/manager/rules/add -X POST  --cookie "XDEBUG_SESSION=XDEBUG_ECLIPSE" -d '{"rule_text":"Stream 2","streamId":"2"}' -H "Authorization: Bearer 1Nc4OyONtmikIsfi" -H "Accept: application/json"  -H "Content-Type: application/json"
  curl http://127.0.0.1:8000/api/manager/rules/delete/1?streamId=1 --cookie "XDEBUG_SESSION=XDEBUG_ECLIPSE" -H "Authorization: Bearer 1Nc4OyONtmikIsfi" -H "Accept: application/json"  -H "Content-Type: application/json"
  curl http://127.0.0.1:8000/api/manager/rules/add -X POST --cookie "XDEBUG_SESSION=XDEBUG_ECLIPSE"  -d '{"rule_text":"Stream 3","streamId":"2"}' -H "Authorization: Bearer 1Nc4OyONtmikIsfi" -H "Accept: application/json"  -H "Content-Type: application/json"
#done

echo "Finish"
