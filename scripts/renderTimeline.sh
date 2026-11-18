#!/bin/sh

# Render ./file into file.png, file.svg and file.map (or file.err with the error report).
# Parameters come from the environment, see includes/Timeline.php.

ET_PHP="${ET_PHP:-php}"
ET_RSVG="${ET_RSVG:-rsvg-convert}"
if [ -z "$ET_ARTICLEPATH" ]; then
	# Not possible/straightforward to use $ in parameter expansion
	ET_ARTICLEPATH='/$1'
fi

errorExit() {
	printf 'mw-msg:' 1>&2
	for arg in "$@"; do
		printf '\t%s' "$arg" 1>&2
	done
	printf '\n' 1>&2
	exit 1
}

# Prints the SVG size as "width height"
size=$("$ET_PHP" scripts/EasyTimeline.php "$ET_ARTICLEPATH" "${ET_FONTFILE:-FreeSans}" "${ET_SVG:-no}") \
	|| errorExit timeline-compilererr

if [ "$ET_SVG" = yes ] && [ -n "$size" ]; then
	# shellcheck disable=SC2086
	set -- $size
	"$ET_RSVG" -w "$1" -h "$2" -o file.png file.svg || errorExit timeline-rsvg-error
fi
echo "$size"
