#!/bin/bash
docker rm docs
docker build -t docs --build-arg DEPLOY_TARGET=local /Users/snikolaev/newdoc
docker run -v $(pwd)/docs:/var/manual --name=docs -p 8008:80 docs


