#!/bin/sh
set -eu

# Explicit, reviewable list. This is a Streams PQ image, not a general-purpose
# Manticore distribution. Keep searchd, replication, Buddy, backup, MySQL,
# language packs, ICU/Jieba, columnar, secondary indexes and KNN itself.
# Manticore's bundle is one package; removing these payload files intentionally
# leaves its dpkg metadata intact. Do not upgrade packages inside this image.
for path in \
    /usr/bin/indexer \
    /usr/bin/indextool \
    /usr/bin/manticore-converter \
    /usr/bin/manticore-load \
    /usr/share/manticore/modules/manticore-load \
    /usr/include/manticore \
    /usr/share/manticore/modules/lib_manticore_knn_embeddings.so \
    /usr/share/manticore/modules/lib_manticore_knn_embeddings.so.*; do
    if [ -e "$path" ] || [ -L "$path" ]; then
        du -sh "$path"
        rm -rf -- "$path"
    fi
done

# Keep copyright notices, component licenses and the package database.
# Manual pages and GNU info pages are not used by the runtime.
rm -rf /usr/share/man /usr/share/info
