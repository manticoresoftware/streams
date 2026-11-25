#!/bin/sh
set -eu

# Explicit, reviewable list. This is a Streams PQ image, not a general-purpose
# Manticore distribution. Keep searchd, Galera, executor, backup, MySQL,
# language packs, ICU/Jieba and secondary indexes.
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

# Buddy is not part of the Streams query path. Keep the executor and
# Backup unchanged, including their PHP runtime and all language data.
# Disable Buddy explicitly instead of relying on a missing-file warning.
sed -i '/^    network_timeout = 5/i\    buddy_path =' /etc/manticoresearch/manticore.conf
# Fail the build if a config refactor prevents the setting from being inserted.
grep -Eq '^[[:space:]]*buddy_path[[:space:]]*=[[:space:]]*$' /etc/manticoresearch/manticore.conf
for path in /usr/share/manticore/modules/manticore-buddy/*; do
    case "$(basename "$path")" in
        LICENSE*|COPYING*|NOTICE*|README*|COPYRIGHT*) continue ;;
    esac
    rm -rf -- "$path"
done
rm -f /usr/bin/manticore-buddy

# The metrics store uses a separate image. Only the PQ/RT pipeline
# image is affected; the secondary index library is retained.
for path in \
    /usr/share/manticore/modules/lib_manticore_columnar*.so* \
    /usr/share/manticore/modules/lib_manticore_knn*.so*; do
    if [ -e "$path" ] || [ -L "$path" ]; then
        du -sh "$path"
        rm -f -- "$path"
    fi
done

# Keep the SQL client, mysqldump, addr2line (searchd crash reports),
# shared libraries, locale runtime data, and all package/license metadata.
# Remove only the offline database/compilation utilities and locale sources.
for tool in mysqlpump mysqlbinlog myisam_ftdump myisamchk myisamlog myisampack \
    ar as c++filt dwp elfedit gprof gprofng ld ld.bfd ld.gold nm objcopy \
    objdump ranlib readelf size strings strip; do
    for path in "/usr/bin/$tool" /usr/bin/*-linux-gnu-"$tool"; do
        if [ -e "$path" ] || [ -L "$path" ]; then
            rm -f -- "$path"
        fi
    done
done
rm -rf /usr/share/i18n/charmaps /usr/share/i18n/locales
