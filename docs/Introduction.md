# Introduction

**Manticore Streams** is an open Kubernetes application for:

* highly efficient
* highly available
* and highly scalable free-text stream filtering

of Apache Kafka topics based on [Manticore Search](https://manticoresearch.com).

With Manticore Streams you can:
* read data from your Kafka topics as long as Manticore Streams has access to it wherever it's located: in your Kubernetes or in your local network or on a remote server
* maintain a small or big list of filtering rules: add, edit, delete them via UI
* if your documents are JSON you can filter not only in full-text mode, but using expressions: `age > 35`, `price < 10.55`, geo spatial search and everything else [Manticore Search](https://manticoresearch.com/) supports
* maintain users via UI. Users can have access to different sources, destinations and filtering rules
* see graphs on matching docs, processing lag and others
* write matching documents to another Kafka topic

With Manticore Streams you don't need to worry about spikes in your incoming data as it can scale out automatically. As soon as the spike is gone it will scale back down.

## About search in reverse
The normal way of doing searches is to store documents and perform search queries against them. However there are cases when we want to apply a query to an incoming new document to check if it matches or not. For example a monitoring system normally doesn't just collect data, but it's also desired to notify users on different events. That can be reaching some threshold for a metric or a certain value that appears in the monitored data. Another similar case is news aggregation. You can notify users about any fresh news, but a user might want to be notified only about certain categories or topics. Going further, they might be only interested about certain "keywords".

This is where the traditional search is not a good fit, since would assume performing the desired search over the entire collection, which gets multiplied by the number of users and we end up with lots of queries running over the entire collection, which can put a lot of extra load.

Google Alerts, AlertHN, Bloomberg Terminal, job sites, classifieds and other systems that let their users subscribe to something use a similar technology.

Applications doing such search in reverse often deal with high load: they have to process thousands of documents per second and can have hundreds of thousands of queries to test each document against. In many cases you can also expect transient spikes in incoming data. To get protected from that and **not over-consume** your resources you have to scale your filtering instances up and down.

Here comes Manticore Streams. You can easily install into onto your Kubernetes cluster as a helm chart and its convenient user interface will let you easily solve the above mentioned tasks.
