#!/bin/bash

while [ -n "$1" ]; do
  case "$1" in
  -namespace)
    param="$2"
    NAMESPACE=$param
    shift
    ;;
  -date)
    param="$2"
    DUMP_DATE=$param
    shift
    ;;
  -force)
    FORCE=true
    shift
    ;;
  --)
    shift
    break
    ;;
  *) echo -e "\e[1;31m$1 is not an option\e[0m" ;;
  esac
  shift
done

if [ ! -n "$NAMESPACE" ]; then
  echo -e "\e[1;31mYou don't set namespace by -namespace flag \e[0m"
  exit 1
fi

if [ ! -n "$DUMP_DATE" ]; then
  echo -e "\e[1;31mYou don't select dump date by -date flag \e[0m"
  exit 1
fi

if [ -d "backups/$DUMP_DATE" ]; then
    cd "backups/$DUMP_DATE"
  else
    echo -e "\e[1;31mYou specified wrong dump date (dump not found) \e[0m"
    exit 1
fi

[ -z "$kubectl" ] && export kubectl=$(which kubectl)
[ -f "$kubectl" ] && echo "kubectl location: $kubectl"
[ ! -f "$kubectl" ] && echo "no kubectl" && exit 1

echo -n "Check is namespace exists: "
if ! kubectl get namespace $NAMESPACE; then
  echo -e "\e[1;31mNamespace is non exists \e[0m" && exit 1
fi

echo "OK"

if [ -z "$FORCE" ]; then
  for filename in *.tar.gz*; do

    backupSearchdVersion=$(echo $filename | cut -d "-" -f 5,6 | cut -d "." -f1,2,3)
  	workerName=`timeout 30 kubectl -n manticore-streams get po -l app.kubernetes.io/component=worker -o name | head -n 1`
    searchdVersion=`timeout 30 kubectl -n manticore-streams exec $workerName -- searchd --version | head -n 2 | head -n 1 | sed 's/@/ /'|cut -d " " -f 2,4| sed 's/ /-/'`

    if [[ ! "$backupSearchdVersion" == "$searchdVersion" ]]; then
        echo "Searchd version mismatch. Exit. You can use -force parameter to override this behavior"
    fi

    break
  done
fi

if [ ! -n "MYSQL_DATABASE" ]; then
  MYSQL_DATABASE=manticore_kafka_ui
fi

if ! MYSQL_POD=$(kubectl get po -n $NAMESPACE | cut -f1 -d" " | grep mysql); then
  echo -e "\e[1;31mCan't find any mysql pods\e[0m"
  continue
else

  echo -e "\e[1;32mUpload mysql dump \e[0m\n"
  while true; do
    kubectl cp database.sql $NAMESPACE/$MYSQL_POD:/tmp/database.sql 1> /dev/null && break \
    || echo -en "\r\e[1;31m Error while uploading mysql dump. Retry \e[0m" && sleep 1
  done


  echo -e "\e[1;32mStart dumping mysql \e[0m"
  while true; do
      kubectl -n $NAMESPACE exec $MYSQL_POD -- bash -c 'mysql -u$MYSQL_USER -p$MYSQL_ROOT_PASSWORD manticore_kafka_ui < /tmp/database.sql' 1> /dev/null && echo "OK" && break \
      || echo -en "\r\e[1;31m Error mysql dump importing. Retry \e[0m" && sleep 1
  done
fi

if ! UI_POD=$(kubectl get po -n $NAMESPACE | cut -f1 -d" " | grep "\-ui"); then
  echo -e "\e[1;31mCan't find any UI pods\e[0m"
  continue
else

  echo -e "\e[1;32mStart chart:post-upgrade job \e[0m"
  while true; do
    kubectl -n $NAMESPACE exec $UI_POD -- bash -c "php artisan chart:post-upgrade" 1> /dev/null && echo "OK" && break \
     || echo -en "\r\e[1;31m Error while checker job running \e[0m" && sleep 1
  done

  echo -e "\e[1;32mStart process:configmap job \e[0m"
  while true; do
    kubectl -n $NAMESPACE exec $UI_POD -- bash -c "php artisan process:configmap" 1> /dev/null && echo "OK" && break \
     || echo -en "\r\e[1;31m Error during process:configmap job runs \e[0m" && sleep 1
  done
fi


if ! SCALER_DEPLOYMENT=$(kubectl get deployments -n $NAMESPACE | cut -f1 -d" " | grep scaler); then
    echo -e "\e[1;31mCan't find scaler pods\e[0m"
    continue
else

    echo -e "\e[1;32mScale scaler pod \e[0m"
    while true; do
        kubectl -n $NAMESPACE scale --replicas=0 deployments/$SCALER_DEPLOYMENT 1> /dev/null && echo "OK" && break \
        || echo -en "\r\e[1;31m Error scaler deployment scaling \e[0m" && sleep 1
    done


    for filename in *.tar.gz*; do
      STREAM_ID=$(echo $filename | grep -o 'm[0-9]\+')
      if ! WORKER_STATEFULSET=$(kubectl get statefulsets -n $NAMESPACE | cut -f1 -d" " | grep $STREAM_ID-pipeline ); then
        echo -e "\e[1;31mCan't find any pipeline with ID $STREAM_ID\e[0m"
        continue
      else
        if ! WORKER_CONTAINER=$(kubectl -n $NAMESPACE get statefulsets $WORKER_STATEFULSET -o \
        jsonpath='{range .spec.template.spec.containers[*]}{.name}{"\n"}{end}' | grep worker ); then
            echo -e "\e[1;31mCan't find any worker containers in $WORKER_STATEFULSET\e[0m"
            continue
          else

            echo -e "\e[1;32mScale pipeline \e[0m"
            while true; do
              kubectl -n $NAMESPACE scale --replicas=1 statefulset/$WORKER_STATEFULSET 1> /dev/null && echo "OK" && break \
              || echo -en "\r\e[1;31m Error pipeline $WORKER_STATEFULSET scaling \e[0m" && sleep 1
            done

            echo -e "\e[1;32mWait until pipeline scaled to 1 \e[0m"
            while true; do
              if [[ "$(kubectl -n $NAMESPACE get statefulset/$WORKER_STATEFULSET -o jsonpath='{.status.currentReplicas}')" == 1 ]]; then
                echo "OK" && break
              fi

              echo -en "\r\e[1;31m Pipeline $WORKER_STATEFULSET count != 1 \e[0m" && sleep 1
            done

            echo -e "\e[1;32mSuspend worker $WORKER_STATEFULSET \e[0m"
            while true; do
              kubectl -n $NAMESPACE patch statefulset $WORKER_STATEFULSET -p \
              '{"spec":{"template":{"spec":{"containers":[{"env":[{"name":"SUSPEND","value":"1"}],"name":"'$WORKER_CONTAINER'"}]}}}}' 1> /dev/null && echo "OK" && break \
              || echo -en "\r\e[1;31m Error worker $WORKER_STATEFULSET suspending \e[0m" && sleep 1
            done

            echo -e "\r\e[1;32mWait until pipeline $WORKER_STATEFULSET-0 will recreated \e[0m"
            while true; do
              if [[ $(kubectl -n $NAMESPACE get po $WORKER_STATEFULSET-0 | cut -f9 -d" " | grep Running ) ]]; then
                echo "OK" && break
              fi
              echo -en "\r\e[1;31m Pipeline $WORKER_STATEFULSET-0 not ready \e[0m" && sleep 5
            done
        fi

          echo -e "\e[1;32mTry upload indexes: cp $filename $NAMESPACE/${WORKER_STATEFULSET}-0:/tmp/indexes.tar.gz\e[0m"
          while true; do
            kubectl cp $filename $NAMESPACE/${WORKER_STATEFULSET}-0:/tmp/indexes.tar.gz 1> /dev/null && echo "OK" && break \
            || echo -en "\r\e[1;31m Error while upload indexes. Retry \e[0m" && sleep 1
          done


          echo -e "\e[1;32mUntar index dump \e[0m"
          while true; do
            kubectl -n $NAMESPACE exec ${WORKER_STATEFULSET}-0 -- bash -c 'tar -zxvf /tmp/indexes.tar.gz --strip=3 -C /tmp' 1> /dev/null && echo "OK" && break \
            || echo -ne "\r\e[1;31m Error while untaring index dump. Retry \e[0m" && sleep 1
          done

          echo -e "\e[1;32mRestore index dump \e[0m"
          while true; do
            kubectl -n $NAMESPACE exec ${WORKER_STATEFULSET}-0 -- manticore-backup --config=/etc/manticoresearch/manticore.conf --backup-dir=/tmp/ --restore && echo "OK" && break \
            || echo -ne "\r\e[1;31m Error while restoring index dump. Retry \e[0m" && sleep 1
          done

          echo -e "\e[1;32mRemove index dump \e[0m"
          while true; do
            kubectl -n $NAMESPACE exec ${WORKER_STATEFULSET}-0 -- rm -rf /tmp/backup-* && echo "OK" && break \
            || echo -ne "\r\e[1;31m Error while removing index dump. Retry \e[0m" && sleep 1
          done


          echo -e "\e[1;32mResume worker \e[0m"
          while true; do
            kubectl -n $NAMESPACE patch statefulset $WORKER_STATEFULSET -p \
            '{"spec":{"template":{"spec":{"containers":[{"env":[{"name":"SUSPEND","value":"0"}],"name":"'$WORKER_CONTAINER'"}]}}}}' 1> /dev/null \
             && echo "OK" && break || echo -en "\r\e[1;31m Error patching statefulset $WORKER_STATEFULSET. Retry \e[0m" && sleep 1
          done
      fi
    done

    echo -e "\e[1;32mScale scaler pod \e[0m"
    while true; do
      kubectl -n $NAMESPACE scale --replicas=1 deployments/$SCALER_DEPLOYMENT 1> /dev/null && echo "OK" && break \
      || echo -en "\r\e[1;31m Error during scaling scaler pod $WORKER_STATEFULSET. Retry \e[0m" && sleep 1
    done
fi







