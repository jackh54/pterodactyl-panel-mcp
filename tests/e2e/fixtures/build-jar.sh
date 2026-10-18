#!/usr/bin/env bash
# Builds fixtures/TestPlugin.jar (the plugin jar the inspect_archive / upload tests use). Needs a JDK.
set -euo pipefail
here=$(cd "$(dirname "$0")" && pwd)
out=$(mktemp -d)
javac --release 17 -d "$out" "$here/jar/com/example/test/TestPlugin.java"
cp "$here/jar/plugin.yml" "$out/"
(cd "$out" && jar --create --file "$here/TestPlugin.jar" plugin.yml com)
rm -rf "$out"
echo "$here/TestPlugin.jar"
