#!/bin/sh

# Get parameters from environment

export ET_PHP="${ET_PHP:-php}"
export ET_PERL="${ET_PERL:-perl}"
export ET_PLOTICUS="${ET_PLOTICUS:-ploticus}"
export ET_RSVG="${ET_RSVG:-rsvg-convert}"
if [ -z "$ET_ARTICLEPATH" ]; then
	# Not possible/straightforward to use $ in parameter expansion
	export ET_ARTICLEPATH='/$1';
fi
export ET_FONTFILE="${ET_FONTFILE:-FreeSans}"
export ET_SVG="${ET_SVG:-no}"

errorExit() {
	printf 'mw-msg:' 1>&2
	for arg in "$@"; do
		printf '\t%s' "$arg" 1>&2
	done
	printf '\n' 1>&2
	exit 1
}

runPhp() {
	"$ET_PHP" "$@"
	status="$?"
	if [ "$status" -ne 0 ]; then
		if [ "$status" -eq 20 ]; then
			# Error already shown
			exit 1
		fi
		errorExit timeline-scripterr "$1" "$status"
	fi
}

runEasyTimeline() {
	if [ ! -x "$ET_PERL" ]; then
		errorExit timeline-perlnotexecutable "$ET_PERL"
	fi
	if [ ! -x "$ET_PLOTICUS" ]; then
		errorExit timeline-ploticusnotexecutable "$ET_PLOTICUS"
	fi
	if [ "$ET_SVG" = yes ]; then
		svg="-s"
	else
		svg=""
	fi
	"$ET_PERL" \
		scripts/EasyTimeline.pl \
		-i "file" \
		-m -P "$ET_PLOTICUS" \
		-T /tmp \
		-A "$ET_ARTICLEPATH" \
		-f "$ET_FONTFILE" \
		"$svg"

	if [ $? -ne 0 ]; then
		errorExit timeline-compilererr
	fi
}

getSVGSize() {
	runPhp scripts/extractSVGSize.php file.svg > dims
	read -r ET_WIDTH ET_HEIGHT < dims
	export ET_WIDTH
	export ET_HEIGHT
}

runRsvg() {
	"$ET_RSVG" \
		-w "$ET_WIDTH" \
		-h "$ET_HEIGHT" \
		-o file.png \
		file.svg
	if [ $? -ne 0 ]; then
		errorExit timeline-rsvg-error
	fi
}

runEasyTimeline
if [ "$ET_SVG" = yes ]; then
	getSVGSize
	runRsvg
fi
