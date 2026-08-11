# Backup

Because have **single** instances of **Mysql** and **Columnar metrics storage**, very important make regular backups

This script allow you to backups all Manticore Streams, excluding statistic which storing in Columnar pod (rules matching, rules count, LAG values)

For backup, just run the script `./backup/backup.sh` with parameters:

* namespace - **Mandatory parameter**. Namespace which the Manticore Streams script is deployed
* mysql-pod* - The name of the pod with the mysql database
* mysql-database* - Mysql database name
* manticore-statefulset* - Name of pods with Manticore. **Not to be confused** with the `*-facade-manticore`.

\* Need only in case a non-default values was chosen

___
After script will finish, it will put the dump of the mysql database, arichive with the indexes and all chart YAMLs to the `./backup/data` folder
