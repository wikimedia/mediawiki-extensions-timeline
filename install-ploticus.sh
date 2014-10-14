#!/bin/sh
## This script downloads, builds and installs a local binary of ploticus
## into this extension's folder, with settings suitable for most UNIX servers.
##
## If ploticus is provided as a package by your distribution and you can get
## it installed, you should instead use that procedure to install ploticus.
##

cd "$(dirname $0)"
mkdir -m 700 ploticus
set -e

cd ploticus
wget http://downloads.sourceforge.net/ploticus/pl241src.tar.gz?download
tar -xzf pl*src.tar.*
cd pl*src
cd src

# Using setting 1 (default), appropriate for UNIX servers: Only pl executable with no X11

# Choose GD with FreeType2

# Uncomment section 4
sed "/Option 4: use your own GD resource with FreeType2 fonts enabled/,/Option 5/ s/^#//g"  Makefile > Makefile2
# And comment section 1
sed "/Option 1: use bundled GD16/,/Option 2: use bundled GD13 (pseudoGIF only)/ s/^/#/g"  Makefile2 > Makefile

make

# Copy to the extension folder
make INSTALLBIN=../../.. install
cd ../../..
rm -r ploticus

echo ''
echo 'Done. Please add the following line to your LocalSettings.php'
echo 'to let MediaWiki know where the binary is located:'

echo "  \$wgTimelineSettings->ploticusCommand = \"$(pwd)/pl\";"
