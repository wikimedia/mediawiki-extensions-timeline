#!/usr/bin/perl
# Copyright (C) 2004 Erik Zachte , email xxx\@chello.nl (nospam: xxx=epzachte)
# This program is free software; you can redistribute it and/or
# modify it under the terms of the GNU General Public License version 2
# as published by the Free Software Foundation.
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
# See the GNU General Public License for more details, at
# http://www.fsf.org/licenses/gpl.html

  use Time::Local ;
  use Getopt::Std ;
  use Cwd ;

  $| = 1; # flush screen output

  print "EasyTimeline, Copyright (C) 2004 Erik Zachte\n" .
        "Email xxx\@chello.nl (nospam: xxx=epzachte)\n\n" .
        "This program is free software; you can redistribute it\n" .
        "and/or modify it under the terms of the \n" .
        "GNU General Public License version 2 as published by\n" .
        "the Free Software Foundation\n" .
        "------------------------------------------------------\n" ;

  &SetImageFormat ;
  &ParseArguments ;
  print "\nInput:  Script file $file_in\n" ;

  $file  = $file_in ;
  $file  =~ s/\.[^\.]*$// ; # remove extension
  $file_bitmap  = $file . "." . $fmt ;
  $file_vector  = $file . ".svg" ;
  $file_png     = $file . ".png" ;
  $file_htmlmap = $file . ".map" ;
  $file_html    = $file . ".html" ;
  $file_errors  = $file . ".err" ;
  print "Output: Image files $file_bitmap & $file_vector\n" ;

  if ($linkmap)
  { print "        Map file $file_htmlmap (add to html for clickable map)\n" ; }
  if ($makehtml)
  { print "        HTML test file $file_html\n" ; }

  # remove previous output
  if (-e $file_bitmap)   { unlink $file_bitmap ; }
  if (-e $file_vector)   { unlink $file_vector ; }
  if (-e $file_png)      { unlink $file_png ; }
  if (-e $file_htmlmap)  { unlink $file_htmlmap ; }
  if (-e $file_html)     { unlink $file_html ; }
  if (-e $file_errors)   { unlink $file_errors ; }

  open "FILE_IN", "<", $file_in ;
  @lines = <FILE_IN> ;
  close "FILE_IN" ;
  &InitVars ;
  &ParseScript ;

  if ($CntErrors == 0)
  { &WritePlotFile ; }

  if ($CntErrors == 1)
  { &Abort ("1 error found") ; }
  elsif ($CntErrors > 1)
  { &Abort ("$CntErrors errors found") ; }
  else
  {
    if (defined @Info)
    {
      print "\nINFO\n" ;
      print @Info ;
      print "\n" ;
    }
    if (defined @Warnings)
    {
      print "\nWARNING(S)\n" ;
      print @Warnings ;
      print "\n" ;
    }

    if (! (-e $file_bitmap))
    {
      print "\nImage $file_bitmap not created.\n" ;
      if ((! (-e "pl.exe")) && (! (-e "pl")))
      { print "\nPloticus not found in local folder. Is it on your system path?\n" ; }
    }
    elsif (! (-e $file_vector))
    {
      print "\nImage $file_vector not created.\n" ;
    }
    else
    { print "\nREADY\nNo errors found.\n" ; }
  }

  exit ;

sub ParseArguments
{
  my $options ;
  getopt ("iTAPe", \%options) ;

  &Abort ("Specify input file as: -i filename") if (! defined (@options {"i"})) ;

  $file_in   = @options {"i"} ;
  $listinput = @options {"l"} ; # list all input lines (not recommended)
  $linkmap   = @options {"m"} ; # make clickmap for inclusion in html
  $makehtml  = @options {"h"} ; # make test html file with gif/png + svg output
  $bypass    = @options {"b"} ; # do not use in Wikipedia:bypass some checks
  $showmap   = @options {"d"} ; # debug: shows clickable areas in gif/png

  				# The following parameters are used by MediaWiki
				# to pass config settings from LocalSettings.php to 
				# the perl script
  $tmpdir    = @options {"T"} ; # For MediaWiki: temp directory to use
  $plcommand = @options {"P"} ; # For MediaWiki: full path of ploticus command
  $articlepath=@options {"A"} ; # For MediaWiki: Path of an article, relative to this servers root

  if (! defined @options {"A"} )
  {
  	$articlepath="http://en.wikipedia.org/wiki/";
  }

  if (! -e $file_in)
  { &Abort ("Input file '" . $file_in . "' not found.") ; }
}

sub InitVars
{
  $True  = 1 ;
  $False = 0 ;
  $CntErrors = 0 ;
  $LinkColor = "brightblue" ;
  $MapPNG = $False ; # switched when link or hint found
  $MapSVG = $False ; # switched when link found
  $WarnTextOutsideArea = 0 ;
  $WarnOnRightAlignedText = 0 ;

  $hPerc    = &EncodeInput ("\%") ;
  $hAmp     = &EncodeInput ("\&") ;
  $hAt      = &EncodeInput ("\@") ;
  $hDollar  = &EncodeInput ("\$") ;
  $hBrO     = &EncodeInput ("\(") ;
  $hBrC     = &EncodeInput ("\)") ;
  $hSemi    = &EncodeInput ("\;") ;
  $hIs      = &EncodeInput ("\=") ;
  $hLt      = &EncodeInput ("\<") ;
  $hGt      = &EncodeInput ("\>") ;
}

sub SetImageFormat
{
  $env = "" ;
  $dir = cwd() ; # is there a better way to detect OS?
  if ($dir =~ /\//) { $env = "Linux" ;   $fmt = "png" ; $pathseparator = "/";}
  if ($dir =~ /\\/) { $env = "Windows" ; $fmt = "gif" ; $pathseparator = "\\";}

  if ($env ne "")
  { print "\nOS $env detected -> create image in $fmt format.\n" ; }
  else
  {
    print "\nOS not detected. Assuming Windows -> create image in $fmt format.\n" ;
    $env = "Windows" ;
  }
}
sub ParseScript
{
  my $command ; # local version, $Command = global
  $LineNo = 0 ;
  $InputParsed = $False ;
  $CommandNext = "" ;
  $DateFormat = "x.y" ;

  &GetCommand ;

  &StoreColor ("white", "gray(0.999)", "") ;

  while (! $InputParsed)
  {
    if ($Command =~ /^\s*$/)
    { &GetCommand ; next ; }

    if (! ($Command =~ /$hIs/))
    { &Error ("Invalid statement. No '=' found.") ;
      &GetCommand ; next ; }

    if ($Command =~ /$hIs.*$hIs/)
    { &Error ("Invalid statement. Multiple '=' found.") ;
      &GetCommand ; next ; }

    my ($name, $value) = split ($hIs, $Command) ;
    $name  =~ s/^\s*(.*?)\s*$/$1/ ;

    if ($name =~ /PlotDividers/i)
    { &Error ("Command 'PlotDividers' has been renamed to 'DrawLines', please adjust.") ;
      &GetCommand ; next ; }

    if ((! ($name =~ /^(?:Define)\s/)) &&
        (! ($name =~ /^(?:AlignBars|BarData|
                          BackgroundColors|Colors|DateFormat|DrawLines|
                          ScaleMajor|ScaleMinor|
                          LegendLeft|LegendTop|
                          ImageSize|PlotArea|Legend|
                          Period|PlotData|
                          TextData|TimeAxis)$/xi)))
    { &ParseUnknownCommand ;
      &GetCommand ; next ; }

    $value =~ s/^\s*(.*?)\s*// ;
    if (! ($name =~ /^(?:BarData|Colors|DrawLines|PlotData|TextData)$/i))
    {
      if ((! (defined ($value))) || ($value eq ""))
      { &Error ("$name definition incomplete. No attributes specified") ;
        &GetCommand ; next ; }
    }

    if ($name =~ /^(?:BackgroundColors|Colors|Period|ScaleMajor|ScaleMinor|TimeAxis)$/i)
    {
      my @attributes = split (" ", $value) ;
      foreach $attribute (@attributes)
      {
        my ($attrname, $attrvalue) = split ("\:", $attribute) ;
        if (! ($name."-".$attrname =~ /^(?:Colors-Value|Colors-Legend|
                                        Period-From|Period-Till|
                                        ScaleMajor-Color|ScaleMajor-Unit|ScaleMajor-Increment|ScaleMajor-Start|
                                        ScaleMinor-Color|ScaleMinor-Unit|ScaleMinor-Increment|ScaleMinor-Start|
                                        BackgroundColors-Canvas|BackgroundColors-Bars|
                                        TimeAxis-Orientation|TimeAxis-Format)$/xi))
        { &Error ("$name definition invalid. Unknown attribute '$attrname'.") ;
          &GetCommand ; next ; }

        if ((! defined ($attrvalue)) || ($attrvalue eq ""))
        { &Error ("$name definition incomplete. No value specified for attribute '$attrname'.") ;
          &GetCommand ; next ; }
      }
    }

       if ($Command =~ /^AlignBars/i)        { &ParseAlignBars ; }
    elsif ($Command =~ /^BackgroundColors/i) { &ParseBackgroundColors ; }
    elsif ($Command =~ /^BarData/i)          { &ParseBarData ; }
    elsif ($Command =~ /^Colors/i)           { &ParseColors ; }
    elsif ($Command =~ /^DateFormat/i)       { &ParseDateFormat ; }
    elsif ($Command =~ /^Define/i)           { &ParseDefine ; }
    elsif ($Command =~ /^DrawLines/i)        { &ParseDrawLines ; }
    elsif ($Command =~ /^ImageSize/i)        { &ParseImageSize ; }
    elsif ($Command =~ /^Legend/i)           { &ParseLegend ; }
    elsif ($Command =~ /^Period/i)           { &ParsePeriod ; }
    elsif ($Command =~ /^PlotArea/i)         { &ParsePlotArea ; }
    elsif ($Command =~ /^PlotData/i)         { &ParsePlotData ; }
    elsif ($Command =~ /^Scale/i)            { &ParseScale ; }
    elsif ($Command =~ /^TextData/i)         { &ParseTextData ; }
    elsif ($Command =~ /^TimeAxis/i)         { &ParseTimeAxis ; }

    &GetCommand ;
  }

  if ($CntErrors == 0)
  { &DetectMissingCommands ; }

  if ($CntErrors == 0)
  { &NormalizeDimensions ; }
}


sub GetLine
{
  if ($#lines < 0)
  { $InputParsed = $True ; return ("") ; }

  $Line = "" ;
  while (($#lines >= 0) && ($Line =~ /^\s*$/))
  {
    $LineNo ++ ;
    $Line = shift (@lines) ;
    chomp ($Line) ;

    if ($listinput)
    { print "$LineNo: " . &DecodeInput ($Line) . "\n" ; }

    $Line =~ s/#>.*?<#//g ;
    if ($Line =~ /#>/)
    {
      $commentstart = $LineNo ;
      $Line =~ s/#>.*?$// ;
    }
    elsif ($Line =~ /<#/)
    {
      undef $commentstart ;
      $Line =~ s/^.*?<#//x ;
    }
    elsif (defined ($commentstart))
    { $Line = "" ; next ; }

    $Line =~ s/\#.*$// ;
    $Line =~ s/\s*$//g ;
    $Line =~ s/\t/ /g ;
  }

  if ($Line !~ /^\s*$/)
  {
    $Line = &EncodeInput ($Line) ;

    if (! ($Line =~ /^\s*Define/i))
    { $Line =~ s/($hDollar[a-zA-Z0-9]+)/&GetDefine($Line,$1)/ge ; }
  }

  if (($#lines < 0) && (defined ($commentstart)))
  { &Error2 ("No matching end of comment found for comment block starting at line $commentstart.\n" .
             "Text between \#> and <\# (multiple lines) or following \# (single line) will be treated as comment.") ; }
  return ($Line) ;
}

sub GetCommand
{
  undef (%Attributes) ;
  $Command = "" ;

  if ($CommandNext ne "")
  {
    $Command = $CommandNext ;
    $CommandNext = "" ;
  }
  else
  { $Command = &GetLine ; }

  if ($Command =~ /^\s/)
  {
    &Error ("New command expected instead of data line (= line starting with spaces). Data line(s) ignored.\n") ;
    $Command = &GetLine ;
    while (($#lines >= 0) && ($Command =~ /^\s/))
    { $Command = &GetLine ; }
  }

  if ($Command =~ /^[^\s]/)
  {
    $line = $Command ;
    $line =~ s/^.*$hIs\s*// ;
    &CollectAttributes ($line) ;
  }
}

sub GetData
{
  undef (%Attributes) ;
  $Command = "" ;
  $NoData = $False ;
  my $line = &GetLine ;

  if ($line =~ /^[^\s]/)
  {
    $CommandNext = $line ;
    $NoData = $True ;
    return ("") ;
  }

  if ($line =~ /^\s*$/)
  {
    $NoData = $True ;
    return ("") ;
  }

  $line =~ s/^\s*//g ;
  &CollectAttributes ($line) ;
}

sub CollectAttributes
{
  my $line = shift ;
  $line =~ s/( $hBrO .+? $hBrC )/&RemoveSpaces($1)/gxe ;
  $line =~ s/\s*\:\s*/:/g ;
  $line =~ s/([a-zA-Z0-9\_]+)\:/lc($1) . ":"/gxe ;

  $line =~ s/(\slink\:[^\s\:]*)\:/$1'colon'/i ; # replace colon (:), would conflict with syntax
  $line =~ s/(\stext\:[^\s\:]*)\:/$1'colon'/i ; # replace colon (:), would conflict with syntax
  $line =~ s/(https?)\:/$1'colon'/i ;             # replace colon (:), would conflict with syntax

  my $text ;
  ($line, $text) = &ExtractText ($line) ;
  $text =~ s/'colon'/:/ ;

  @Fields = split (" ", $line) ;

  $name = "" ;
  foreach $field (@Fields)
  {
    if ($field =~ /\:/)
    {
      ($name, $value) = split (":", $field) ;
      $name  =~ s/^\s*(.*)\s*$/lc($1)/gxe ;
      $value =~ s/^\s*(.*)\s*$/$1/gxe ;
      if (($name ne "bar") && ($name ne "text") && ($name ne "link") && ($name ne "legend")) #  && ($name ne "hint")
      { $value = lc ($value) ; }

      if ($name eq "link") # restore colon
      { $value =~ s/'colon'/:/ ; }

      if ($value eq "")
      {
        if ($name =~ /Text/i)
        { $value = " " ; }
        else
        { &Error ("No value specified for attribute '$name'. Attribute ignored.") ; }
      }
      else
      { @Attributes {$name} = $value ; }
    }
    else
    {
      if (defined (@Attributes {"single"}))
      { &Error ("Invalid attribute '$field' ignored.\nSpecify attributes as 'name:value' pair(s).") ; }
      else
      {
        $field  =~ s/^\s*(.*)\s*$/$1/gxe ;
        @Attributes {"single"} = $field ;
      }
    }
  }
  if (($name ne "") && (@Attributes {"single"} ne ""))
  {
    &Error ("Invalid attribute '" . @Attributes {"single"} . "' ignored.\nSpecify attributes as 'name:value' pairs.") ;
    delete (@Attributes {"single"}) ;
  }

  if ((defined ($text)) && ($text ne ""))
  { @Attributes {"text"} = &ParseText ($text) ; }
}

sub GetDefine
{
  my $command = shift ;
  my $const = shift ;
  $const = lc ($const) ;
  my $value = @Consts {lc ($const)} ;
  if (! defined ($value))
  {
    &Error ("Unknown constant. 'Define $const = ... ' expected.") ;
    return ($const);
  }
  return ($value) ;
}

sub ParseAlignBars
{
  $align = @Attributes {"single"} ;
  if (! ($align =~ /^(?:justify|early|late)$/i))
  { &Error ("AlignBars value '$align' invalid. Specify 'justify', 'early' or 'late'.") ; return ; }

  $AlignBars = lc ($align) ;
}

sub ParseBackgroundColors
{
  if (! &ValidAttributes ("BackgroundColors"))
  { &GetData ; next ;}

  foreach $attribute (keys %Attributes)
  {
    my $attrvalue = @Attributes {$attribute} ;

    if ($attribute =~ /Canvas/i)
    {
      if (! &ColorPredefined ($attrvalue))
      {
        if (! defined (@Colors {lc ($attrvalue)}))
        { &Error ("BackgroundColors definition invalid. Attribute '$attribute' unknown color '$attrvalue'.") ;  return ; }
      }
      if (defined (@Colors {lc ($attrvalue)}))
      { @Attributes {"canvas"} = @Colors { lc ($attrvalue) } ; }
      else
      { @Attributes {"canvas"} = lc ($attrvalue) ; }
    }
    elsif ($attribute =~ /Bars/i)
    {
      if (! defined (@Colors {lc ($attrvalue)}))
      { &Error ("BackgroundColors definition invalid. Attribute '$attribute' unknown color '$attrvalue'.") ;  return ; }

      @Attributes {"bars"} = lc ($attrvalue) ;
    }
  }

  %BackgroundColors = %Attributes ;
}

sub ParseBarData
{
  &GetData ;
  if ($NoData)
  { &Error ("Data expected for command 'BarData', but line is not indented.\n") ; return ; }

  my ($bar, $text, $link, $hint) ;

  BarData:
  while ((! $InputParsed) && (! $NoData))
  {
    if (! &ValidAttributes ("BarData"))
    { &GetData ; next ;}

    $bar = "" ; $link = "" ; $hint = "" ;

    my $data2 = $data ;
    ($data2, $text) = &ExtractText ($data2) ;
    @Attributes = split (" ", $data2) ;

    foreach $attribute (keys %Attributes)
    {
      my $attrvalue = @Attributes {$attribute} ;

      if ($attribute =~ /^Bar$/i)
      {
        $bar = $attrvalue ;
      }
      elsif ($attribute =~ /^Text$/i)
      {
        $text = $attrvalue ;
        $text =~ s/\\n/~/gs ;
        if ($text =~ /\~/)
        { &Warning ("BarData attribute 'text' contains ~ (tilde).\n" .
                    "Tilde will not be translated into newline character (only in PlotData)") ; }
        if ($text =~ /\^/)
        { &Warning ("BarData attribute 'text' contains ^ (caret).\n" .
                    "Caret will not be translated into tab character (only in PlotData)") ; }
      }
      elsif ($attribute =~ /^Link$/i)
      {
        $link = &ParseText ($attrvalue) ;

        if ($link =~ /\[.*\]/)
        { &Error ("BarData attribute 'link' contains implicit (wiki style) link.\n" .
                  "Use implicit link style with attribute 'text' only.\n") ;
          &GetData ; next BarData ; }

        $link = &EncodeURL (&NormalizeURL ($link)) ;
        $MapPNG = $True ;
      }
    }

    if ($link ne "")
    {
     if ($text =~ /\[.*\]/)
      {
        &Warning ("BarData contains implicit link(s) in attribute 'text' and explicit attribute 'link'.\n" .
                  "Implicit link(s) ignored.") ;
        $text =~ s/\[+ (?:[^\|]* \|)? ([^\]]*) \]+/$1/gx ;
      }
    }

    if ($bar !~ /[a-zA-Z0-9\_]+/)
    { &Error ("BarData attribute bar:'$bar' invalid.\nUse only characters 'a'-'z', 'A'-'Z', '0'-'9', '_'\n") ;
      &GetData ; next BarData ; }

    push @Bars, $bar ;
    if ($text ne "")
    { @BarText {lc ($bar)} = $text ; }

    if ($link ne "")
    { @BarLink {lc ($bar)} = $link ; }

    &GetData ;
  }
}

sub ParseColors
{

  &GetData ;
  if ($NoData)
  { &Error ("Data expected for command 'Colors', but line is not indented.\n") ; return ; }

  my $addtolegend = $False ;
  my $legendvalue = "" ;
  my $colorvalue  = "" ;

  Colors:
  while ((! $InputParsed) && (! $NoData))
  {
    if (! &ValidAttributes ("Colors"))
    { &GetData ; next ;}

    foreach $attribute (keys %Attributes)
    {
      my $attrvalue = @Attributes {$attribute} ;

      if ($attribute =~ /Id/i)
      {
        $colorname = $attrvalue ;
      }
      elsif ($attribute =~ /Legend/i)
      {
        $addtolegend = $True ;
        $legendvalue = $attrvalue ;
        if ($legendvalue =~ /^[yY]$/)
        { push @LegendData, $colorname ; }
        elsif (! ($attrvalue =~ /^[nN]$/))
        {
          $legendvalue = &ParseText ($legendvalue) ;
          push @LegendData, $legendvalue ;
        }
      }
      elsif ($attribute =~ /Value/i)
      {
        $colorvalue = $attrvalue ;
        if ($colorvalue =~ /^white$/i)
        { $colorvalue = "gray" . $hBrO . "0.999" . $hBrC ; }
      }
    }

    if (&ColorPredefined ($colorvalue))
    {
      &StoreColor ($colorname, $colorvalue, $legendvalue) ;
      &GetData ; next Colors ;
    }

    if ($colorvalue =~ /^[a-z]+$/i)
    {
      if (! ($colorvalue =~ /gray|rgb|hsb/i))
      { &Error ("Color value invalid: unknown constant '$colorvalue'.") ;
        &GetData ; next Colors ; }
    }

    if (! ($colorvalue =~ /^(?:gray|rgb|hsb) $hBrO .+? $hBrC/xi))
    { &Error ("Color value invalid. Specify constant or 'gray/rgb/hsb(numeric values)' ") ;
      &GetData ; next Colors ; }

    if ($colorvalue =~ /^gray/i)
    {
      if ($colorvalue =~ /gray $hBrO (?:0|1|0\.\d+) $hBrC/xi)
      { &StoreColor ($colorname, $colorvalue, $legendvalue) ; }
      else
      { &Error ("Color value invalid. Specify 'gray(x) where 0 <= x <= 1' ") ; }

      &GetData ; next Colors ;
    }

    if ($colorvalue =~ /^rgb/i)
    {
      my $colormode = substr ($colorvalue,0,3) ;
      if ($colorvalue =~ /rgb $hBrO
                                 (?:0|1|0\.\d+) \,
                                 (?:0|1|0\.\d+) \,
                                 (?:0|1|0\.\d+)
                              $hBrC/xi)
      { &StoreColor ($colorname, $colorvalue, $legendvalue) ; }
      else
      { &Error ("Color value invalid. Specify 'rgb(r,g,b) where 0 <= r,g,b <= 1' ") ; }

      &GetData ; next Colors ;
    }

    if ($colorvalue =~ /^hsb/i)
    {
      my $colormode = substr ($colorvalue,0,3) ;
      if ($colorvalue =~ /hsb $hBrO
                                 (?:0|1|0\.\d+) \,
                                 (?:0|1|0\.\d+) \,
                                 (?:0|1|0\.\d+)
                              $hBrC/xi)
      { &StoreColor ($colorname, $colorvalue, $legendvalue) ; }
      else
      { &Error ("Color value invalid. Specify 'hsb(h,s,b) where 0 <= h,s,b <= 1' ") ; }

      &GetData ; next Colors ;
    }

    &Error ("Color value invalid.") ;
    &GetData ;
  }
}

sub StoreColor
{
  my $colorname   = shift ;
  my $colorvalue  = shift ;
  my $legendvalue = shift ;
  if (defined (@Colors {lc ($colorname)}))
  { &Warning ("Color '$colorname' redefined.") ; }
  @Colors      {lc ($colorname)} = lc ($colorvalue) ;
  if ((defined ($legendvalue)) && ($legendvalue ne ""))
  { @ColorLabels {lc ($colorname)} = $legendvalue ; }
}

sub ParseDateFormat
{
  my $datevalue = lc (@Attributes {"single"}) ;
  $datevalue =~ s/\s//g ;
  $datevalue = lc ($datevalue) ;
  if (($datevalue ne "dd/mm/yyyy") && ($datevalue ne "mm/dd/yyyy") && ($datevalue ne "yyyy") && ($datevalue ne "x.y"))
  { &Error ("Invalid DateFormat. Specify as 'dd/mm/yyyy', 'mm/dd/yyyy', 'yyyy' or 'x.y'\n" .
            "  (use first two only for years >= 1800)\n") ;  return ; }

  $DateFormat = $datevalue ;
}

sub ParseDefine
{
  my $command = $Command ;
  my $command2 = $command ;
  $command2 =~ s/^Define\s*//i ;

  my ($name, $value) = split ($hIs, $command2) ;
  $name  =~ s/^\s*(.*?)\s*$/$1/g ;
  $value =~ s/^\s*(.*?)\s*$/$1/g ;

  if (! ($name =~ /^$hDollar/))
  { &Error ("Define '$name' invalid. Name does not start with '\$'.") ;  return ; }
  if (! ($name =~ /^$hDollar[a-zA-Z0-9\_]+$/))
  { &Error ("Define '$name' invalid. Valid characters are 'a'-'z', 'A'-'Z', '0'-'9', '_'.") ;  return ; }

  $value =~ s/($hDollar[a-zA-Z0-9]+)/&GetDefine($command,$1)/ge ;
  @Consts {lc ($name)} = $value ;
}

sub ParseDrawLines
{
  &GetData ;
  if ($NoData)
  { &Error ("Data expected for command 'DrawLines', but line is not indented.\n") ; return ; }

  if ((! (defined ($DateFormat))) || (! (defined (@Period {"from"}))))
  {
    if (! (defined ($DateFormat)))
    { &Error ("DrawLines invalid. No (valid) command 'DateFormat' specified in previous lines.") ; }
    else
    { &Error ("DrawLines invalid. No (valid) command 'Period' specified in previous lines.") ; }

    while ((! $InputParsed) && (! $NoData))
    { &GetData ; }
    return ;
  }

  my ($at, $color, $ValidDate) ;

  my $data2 = $data ;

  DrawLines:
  while ((! $InputParsed) && (! $NoData))
  {
    if (! &ValidAttributes ("DrawLines"))
    { &GetData ; next ;}

    foreach $attribute (keys %Attributes)
    {
      my $attrvalue = @Attributes {$attribute} ;

      if ($attribute =~ /At/i)
      {
        if ($attrvalue =~ /^Start$/i)
        { $attrvalue = @Period {"from"} ; }

        if ($attrvalue =~ /^End$/i)
        { $attrvalue = @Period {"till"} ; }

        if (! &ValidDateFormat ($attrvalue))
        { &Error ("DrawLines attribute '$attribute' invalid.\n" .
                  "Date does not conform to specified DateFormat '$DateFormat'.") ;
          &GetData ; next DrawLines ; }

        if (! &ValidDateRange ($attrvalue))
        { &Error ("DrawLines attribute '$attribute' invalid.\n" .
                  "Date '$attrvalue' not within range as specified by command DateFormat.") ;
          &GetData ; next DrawLines ; }

#       if (substr ($attrvalue,6,4) < 1800)
#       { &Error ("DrawLines attribute '$attribute' invalid. Specify year >= 1800.") ;
#         &GetData ; next DrawLines ; }

        @Attributes {$attribute} = $attrvalue ;
      }
      elsif ($attribute =~ /Color/i)
      {
        if ((! &ColorPredefined ($attrvalue)) && (! defined (@Colors {lc ($attrvalue)})))
        { &Error ("DrawLines  attribute '$attribute' invalid. Unknown color '$attrvalue'.") ;
          &GetData ; next DrawLines ; }
      }
    }

    if (@Attributes {"color"} eq "")
    { @Attributes {"color"} = "black" ; }

    push @DrawLines, sprintf ("%s,%s\n", @Attributes {"at"}, lc (@Attributes {"color"})) ;

    &GetData ;
  }
}

sub ParseImageSize
{
  if (! &ValidAttributes ("ImageSize")) { return ; }

  foreach $attribute (keys %Attributes)
  {
    my $attrvalue = @Attributes {$attribute} ;
    if (! &ValidAbs ($attrvalue))
    { &Error ("ImageSize attribute '$attribute' invalid.\n" .
              "Specify value as x[.y][px, in, cm] examples: '200', '20px', '1.3in'") ; return ; }
#   if ($attribute =~ /Width/i)
#   { @Attributes {"width"} = $attrvalue ; }
#   elsif ($attribute =~ /Height/i)
#   { @Attributes {"height"} = $attrvalue ; }
  }

  %Image = %Attributes ;
}

sub ParseLegend
{
  if (! &ValidAttributes ("Legend")) { return ; }

  foreach $attribute (keys %Attributes)
  {
    my $attrvalue = @Attributes {$attribute} ;

    if ($attribute =~ /Columns/i)
    {
      if (($attrvalue < 1) || ($attrvalue > 4))
      { &Error ("Legend attribute 'columns' invalid. Specify 1,2,3 or 4") ; return ; }
    }
    elsif ($attribute =~ /Orientation/i)
    {
      if (! ($attrvalue =~ /^(?:hor|horizontal|ver|vertical)$/i))
      { &Error ("Legend attribute '$attrvalue' invalid. Specify hor[izontal] or ver[tical]") ; return ; }

      @Attributes {"orientation"} = substr ($attrvalue,0,3) ;
    }
    elsif ($attribute =~ /Position/i)
    {
      if (! ($attrvalue =~ /^(?:top|bottom|right)$/i))
      { &Error ("Legend attribute '$attrvalue' invalid.\nSpecify top, bottom or right") ; return ; }
    }
    elsif ($attribute =~ /Left/i)
    {
      if (! &ValidAbsRel ($attrvalue))
      { &Error ("Legend attribute '$attribute' invalid.\nSpecify value as x[.y][px, in, cm] examples: '200', '20px', '1.3in'") ; return ; }    }
    elsif ($attribute =~ /Top/i)
    {
      if (! &ValidAbsRel ($attrvalue))
      { &Error ("Legend attribute '$attribute' invalid.\nSpecify value as x[.y][px, in, cm] examples: '200', '20px', '1.3in'") ; return ; }    }
    elsif ($attribute =~ /ColumnWidth/i)
    {
      if (! &ValidAbsRel ($attrvalue))
      { &Error ("Legend attribute '$attribute' invalid.\nSpecify value as x[.y][px, in, cm] examples: '200', '20px', '1.3in'") ; return ; }
    }
  }

  if (defined (@Attributes {"position"}))
  {
    if (defined (@Attributes {"left"}))
    { &Error ("Legend definition invalid. Attributes 'position' and 'left' are mutually exclusive.") ; return ; }
  }
  else
  {
    if ((! defined (@Attributes {"left"})) && (! defined (@Attributes {"top"})))
    {
      &Info ("Legend definition: none of attributes 'position', 'left' or 'top' have been defined. Position 'bottom' assumed.") ;
      @Attributes {"position"} = "bottom" ;
    }
    elsif ((! defined (@Attributes {"left"})) || (! defined (@Attributes {"top"})))
    { &Error ("Legend definition invalid. Specify 'position', or 'left' & 'top'.") ; return ; }
  }

  if (@Attributes {"position"} =~ /right/i)
  {
    if (defined (@Attributes {"columns"}))
    { &Error ("Legend definition invalid.\nAttribute 'columns' and 'position:right' are mutually exclusive.") ; return ; }
    if (defined (@Attributes {"columnwidth"}))
    { &Error ("Legend definition invalid.\nAttribute 'columnwidth' and 'position:right' are mutually exclusive.") ; return ; }
  }

  if (@Attributes {"orientation"} =~ /hor/i)
  {
    if (@Attributes {"position"} =~ /right/i)
    { &Error ("Legend definition invalid.\n'position:right' and 'orientation:horizontal' are mutually exclusive.") ; return ; }
    if (defined (@Attributes {"columns"}))
    { &Error ("Legend definition invalid.\nAttribute 'columns' and 'orientation:horizontal' are mutually exclusive.") ; return ; }
    if (defined (@Attributes {"columnwidth"}))
    { &Error ("Legend definition invalid.\nAttribute 'columnwidth' and 'orientation:horizontal' are mutually exclusive.") ; return ; }
  }

  if ((@Attributes {"orientation"} =~ /hor/i) && (defined (@Attributes {"columns"})))
  { &Error ("Legend definition invalid.\nDo not specify attribute 'columns' with 'orientation:horizontal'.") ; return ; }

  if (@Attributes {"columns"} > 1)
  {
    if ((defined (@Attributes {"left"})) && (! defined (@Attributes {"columnwidth"})))
    { &Error ("Legend attribute 'columnwidth' not defined.\nThis is needed when attribute 'left' is specified.") ; return ; }
  }

  if (! defined (@Attributes {"orientation"}))
  { @Attributes {"orientation"} = "ver" ; }

  %Legend = %Attributes ;
}

sub ParsePeriod
{
  if (! defined ($DateFormat))
  { &Error ("Period definition ambiguous. No (valid) command 'DateFormat' specified in previous lines.") ;  return ; }

  if (! ValidAttributes ("Period")) { return ; }

  foreach $attribute (keys %Attributes)
  {
    my $attrvalue = @Attributes {$attribute} ;

    if ($DateFormat eq "yyyy")
    {
       if (! ($attrvalue =~ /^\-?\d+$/))
       { &Error ("Period definition invalid.\nInvalid year '$attrvalue' specified for attribute '$attribute'.") ;  return ; }
    }
    elsif ($DateFormat eq "x.y")
    {
       if (! ($attrvalue =~ /^\-?\d+(?:\.\d+)?$/))
       { &Error ("Period definition invalid.\nInvalid year '$attrvalue' specified for attribute '$attribute'.") ;  return ; }
    }
    else
    {
      $ValidDate = &ValidDateFormat ($attrvalue) ;
      if (! $ValidDate)
      { &Error ("Period attribute '$attribute' invalid.\n" .
                "Date does not conform to specified DateFormat '$DateFormat'.") ;  return ; }
      if (substr ($attrvalue,6,4) < 1800)
      { &Error ("Period attribute '$attribute' invalid. Specify year >= 1800.") ;  return ; }
    }
  }

  %Period = %Attributes ;
}

sub ParsePlotArea
{
  if (! &ValidAttributes ("PlotArea")) { return ; }

  foreach $attribute (@Attributes)
  {
    my $attrvalue = @Attributes {$attribute} ;
    if (! &ValidAbsRel ($attrvalue))
    { &Error ("PlotArea attribute '$attribute' invalid.\n" .
              "Specify value as x[.y][px, in, cm, %] examples: '200', '20px', '1.3in', '80%'") ; return ; }
  }

  %PlotArea = %Attributes ;
}

#                         command Bars found ?
#                  Y                |                   N
#             bar: found ?          |               bar: found ?
#        Y | N                      |              Y | N
# validate | previous bar: found?   | @Bars contains | previous bar: found?
#   bar:.. |                        |        bar: ?  |    Y | N
#          |     Y | N              |                | copy | assume
#          | copy  |  $#Bars ..     | Y  | N         | bar: | bar:---
#          |  bar: |== 0            | -  | assume    |      |
#          |       | assume bar:--- |    |  bar:---  |      |
#          |       |== 1            |
#          |       | assume @Bar[0] |
#          |       |> 1             |
#          |       | err            |
sub ParsePlotData
{
  if (defined (@Bars))
  { $BarsCommandFound = $True ; }
  else
  { $BarsCommandFound = $False ; }
  $prevbar = "" ;

  &GetData ;
  if ($NoData)
  { &Error ("Data expected for command 'PlotData', but line is not indented.\n") ; return ; }

  my ($bar, $at, $from, $till, $color, $bgcolor, $textcolor, $fontsize, $width,
      $text, $align, $shift, $mark, $markcolor, $link, $hint) ;
  if ((! (defined ($DateFormat))) || (! (defined (@Period {"from"}))))
  {
    if (! (defined ($DateFormat)))
    { &Error ("PlotData invalid. No (valid) command 'DateFormat' specified in previous lines.") ; }
    else
    { &Error ("PlotData invalid. No (valid) command 'Period' specified in previous lines.") ; }

    while ((! $InputParsed) && (! $NoData))
    { &GetData ; }
    return ;
  }

  PlotData:
  while ((! $InputParsed) && (! $NoData))
  {
    if (! &ValidAttributes ("PlotData"))
    { &GetData ; next ;}

    $bar = "" ;
    $at = "" ; $from = "" ; $till = "" ;
    $color = "" ; $bgcolor = "" ; $textcolor = "" ; $fontsize = "" ; $width = "" ;
    $text = "" ; $align = "" ; $shift = "" ;
    $mark = "" ; $markcolor = "" ;
    $link = "" ; $hint = "" ;

    if (defined (@PlotDefs {"bar"}))       { $bar       = @PlotDefs {"bar"} ; }
    if (defined (@PlotDefs {"color"}))     { $color     = @PlotDefs {"color"} ; }
    if (defined (@PlotDefs {"bgcolor"}))   { $bgcolor   = @PlotDefs {"bgcolor"} ; }
    if (defined (@PlotDefs {"textcolor"})) { $textcolor = @PlotDefs {"textcolor"} ; }
    if (defined (@PlotDefs {"fontsize"}))  { $fontsize  = @PlotDefs {"fontsize"} ; }
    if (defined (@PlotDefs {"width"}))     { $width     = @PlotDefs {"width"} ; }
    if (defined (@PlotDefs {"align"}))     { $align     = @PlotDefs {"align"} ; }
    if (defined (@PlotDefs {"shift"}))     { $shift     = @PlotDefs {"shift"} ; }
    if (defined (@PlotDefs {"mark"}))      { $mark      = @PlotDefs {"mark"} ; }
    if (defined (@PlotDefs {"markcolor"})) { $markcolor = @PlotDefs {"markcolor"} ; }
#   if (defined (@PlotDefs {"link"}))      { $link      = @PlotDefs {"link"} ; }
#   if (defined (@PlotDefs {"hint"}))      { $hint      = @PlotDefs {"hint"} ; }

    foreach $attribute (keys %Attributes)
    {
      my $attrvalue = @Attributes {$attribute} ;

      if ($attribute =~ /^Bar$/i)
      {
        if (! ($attrvalue =~ /[a-zA-Z0-9\_]+/))
        { &Error ("PlotData attribute '$attribute' invalid.\n" .
                  "Use only characters 'a'-'z', 'A'-'Z', '0'-'9', '_'\n") ;
          &GetData ; next  PlotData ; }

        $attrvalue2 = $attrvalue ;

        if ($BarsCommandFound)
        {
          if (! &BarDefined ($attrvalue2))
          { &Error ("PlotData invalid. Bar '$attrvalue' not (properly) defined.") ;
            &GetData ; next PlotData ; }
        }
        else
        {
          if (! &BarDefined ($attrvalue2))
          { push @Bars, $attrvalue2 ; }
        }
        $bar = $attrvalue2 ;
        $prevbar = $bar ;
      }
      elsif ($attribute =~ /^At|From|Till$/i)
      {
        if ($attrvalue =~ /^Start$/i)
        { $attrvalue = @Period {"from"} ; }
        if ($attrvalue =~ /^End$/i)
        { $attrvalue = @Period {"till"} ; }

        if (! &ValidDateFormat ($attrvalue))
        {
          &Error ("PlotData attribute '$attribute' invalid.\n" .
                  "Date '$attrvalue' does not conform to specified DateFormat $DateFormat.") ;
          &GetData ; next PlotData ; }

        if (! &ValidDateRange ($attrvalue))
        { &Error ("Plotdata attribute '$attribute' invalid.\n" .
                  "Date '$attrvalue' not within range as specified by command DateFormat.") ;

          &GetData ; next PlotData ; }

        if ($attribute =~ /^At$/i)
        { $at = $attrvalue ; }
        elsif ($attribute =~ /^From$/i)
        { $from = $attrvalue ; }
        else
        { $till = $attrvalue ; }
      }
#      elsif ($attribute =~ /^From$/i)
#      {
#        if ($attrvalue =~ /^Start$/i)
#        { $attrvalue = @Period {"from"} ; }

#        if (! &ValidDateFormat ($attrvalue))
#        { &Error ("PlotData invalid.\nDate '$attrvalue' does not conform to specified DateFormat $DateFormat.") ;
#          &GetData ; next PlotData ; }

#        if (! &ValidDateRange ($attrvalue))
#        { &Error ("Plotdata attribute 'from' invalid.\n" .
#                  "Date '$attrvalue' not within range as specified by command DateFormat.") ;
#          &GetData ; next PlotData ; }

#        $from = $attrvalue ;
#      }
#      elsif ($attribute =~ /^Till$/i)
#      {
#        if ($attrvalue =~ /^End$/i)
#        { $attrvalue = @Period {"till"} ; }

#        if (! &ValidDateFormat ($attrvalue))
#        { &Error ("PlotData invalid. Date '$attrvalue' does not conform to specified DateFormat $DateFormat.") ;
#          &GetData ; next PlotData ; }

#        if (! &ValidDateRange ($attrvalue))
#        { &Error ("Plotdata attribute 'till' invalid.\n" .
#                  "Date '$attrvalue' not within range as specified by command DateFormat.") ;
#          &GetData ; next PlotData ; }

#        $till = $attrvalue ;
#      }
      elsif ($attribute =~ /^Color$/i)
      {
        if (! &ColorPredefined ($attrvalue))
        {
          if (! defined (@Colors {lc ($attrvalue)}))
          { &Error ("PlotData invalid. Attribute '$attribute' has unknown color '$attrvalue'.") ;
            &GetData ; next PlotData ; }
        }
        if (defined (@Colors {lc ($attrvalue)}))
        { $color = @Colors { lc ($attrvalue) } ; }
        else
        { $color = lc ($attrvalue) ; }

        $color = $attrvalue ;
      }
      elsif ($attribute =~ /^BgColor$/i)
      {
        if (! &ColorPredefined ($attrvalue))
        {
          if (! defined (@Colors {lc ($attrvalue)}))
          { &Error ("PlotData invalid. Attribute '$attribute' has unknown color '$attrvalue'.") ;
            &GetData ; next PlotData ; }
        }
        if (defined (@Colors {lc ($attrvalue)}))
        { $bgcolor = @Colors { lc ($attrvalue) } ; }
        else
        { $bgcolor = lc ($attrvalue) ; }
      }
      elsif ($attribute =~ /^TextColor$/i)
      {
        if (! &ColorPredefined ($attrvalue))
        {
          if (! defined (@Colors {lc ($attrvalue)}))
          { &Error ("PlotData invalid. Attribute '$attribute' has unknown color '$attrvalue'.") ;
            &GetData ; next PlotData ; }
        }
        if (defined (@Colors {lc ($attrvalue)}))
        { $textcolor = @Colors { lc ($attrvalue) } ; }
        else
        { $textcolor = lc ($attrvalue) ; }
      }
      elsif ($attribute =~ /^Width$/i)
      {
        $width = &Normalize ($attrvalue) ;
        if ($width > $MaxBarWidth)
        { $MaxBarWidth = $width ; }
      }
      elsif ($attribute =~ /^FontSize$/i)
      {
        if (($attrvalue !~ /\d+(?:\.\d)?/) && ($attrvalue !~ /xs|s|m|l|xl/i))
        { &Error ("PlotData invalid. Specify for attribute '$attribute' a number of XS,S,M,L,XL.") ;
          &GetData ; next PlotData ; }

        $fontsize = $attrvalue ;
        if ($fontsize =~ /XS|S|M|L|XL/i)
        {
          if ($fontsize !~ /xs|s|m|l|xl/i)
          {
            if ($fontsize < 6)
            { &Warning ("TextData attribute 'fontsize' value too low. Font size 6 assumed.\n") ;
              $fontsize = 6 ; }
            if ($fontsize > 30)
            { &Warning ("TextData attribute 'fontsize' value too high. Font size 30 assumed.\n") ;
              $fontsize = 30 ; }
          }
        }
      }
      elsif ($attribute =~ /^Align$/i)
      {
        $align = $attrvalue ;
      }
      elsif ($attribute =~ /^Shift$/i)
      {
        $shift = $attrvalue ;
        $shift =~ s/$hBrO(.*?)$hBrC/$1/ ;
        $shift =~ s/\s//g ;
        ($shiftx,$shifty) = split (",", $shift) ;
        $shiftx = &Normalize ($shiftx) ;
        $shifty = &Normalize ($shifty) ;

        if (($shiftx < -10) || ($shiftx > 10) || ($shifty < -10) || ($shifty > 10))
        { &Error ("PlotData invalid. Attribute '$shift', specify value(s) between -1000 and 1000 pixels = -10 and 10 inch.") ;
          &GetData ; next PlotData ; }

        $shift = $shiftx . "," . $shifty ;
      }
      elsif ($attribute =~ /^Text$/i)
      {
        $text = &ParseText ($attrvalue) ;
        $text =~ s/\\n/\n/g ;
        if ($text =~ /\^/)
        { &Warning ("TextData attribute 'text' contains ^ (caret).\n" .
                    "Caret symbol will not be translated into tab character (use TextData when tabs are needed)") ; }

        $text=~ s/(\[\[ [^\]]* \n [^\]]* \]\])/&NormalizeWikiLink($1)/gxe ;
      }
      elsif ($attribute =~ /^Link$/i)
      {
        $link = &ParseText ($attrvalue) ;
        $link = &EncodeURL (&NormalizeURL ($link)) ;
      }
#     elsif ($attribute =~ /^Hint$/i)
#     {
#       $hint = &ParseText ($attrvalue) ;
#       $hint =~ s/\\n/\n/g ;
#     }
      elsif ($attribute =~ /^Mark$/i)
      {
        $attrvalue =~ s/$hBrO (.*) $hBrC/$1/x ;
        (@suboptions) = split (",", $attrvalue) ;
        $mark = @suboptions [0] ;
        if (! ($mark =~ /^Line$/i))
        { &Error ("PlotData invalid. Value '$mark' for attribute 'mark' unknown.") ;
          &GetData ; next PlotData ; }

        if (defined (@suboptions [1]))
        {
          $markcolor = @suboptions [1] ;
          if (! defined (@Colors {lc ($markcolor)}))
          { &Error ("PlotData invalid. Attribute 'mark': unknown color '$markcolor'.") ;
            &GetData ; next PlotData ; }

          $markcolor = lc ($markcolor) ;
        }
        else
        { $markcolor = "black" ; }
      }
      else
      { &Error ("PlotData invalid. Unknown attribute '$attribute' found.") ;
        &GetData ; next PlotData ; }
    }

#    if ($text =~ /\[\[.*\[\[/s)
#    { &Error ("PlotData invalid. Text segment '$text' contains more than one wiki link. Only one allowed.") ;
#      &GetData ; next PlotData ; }

#    if (($text ne "") || ($link ne ""))
#    { ($text, $link, $hint) = &ProcessWikiLink ($text, $link, $hint) ; }

    if ($bar ne "")
    {
      if (! defined (@BarText {lc($bar)}))
      { @BarText {lc($bar)} = $bar ; }
      if (! defined (@BarWidths {$bar}))
      { @BarWidths {$bar} = 0 ; }
    }

    if (($at eq "") && ($from eq "") && ($till eq "")) # upd defaults
    {
      if ($bar        ne "") { @PlotDefs {"bar"}       = $bar ; }
      if ($color      ne "") { @PlotDefs {"color"}     = $color ; }
      if ($bgcolor    ne "") { @PlotDefs {"bgcolor"}   = $bgcolor ; }
      if ($textcolor  ne "") { @PlotDefs {"textcolor"} = $textcolor ; }
      if ($fontsize   ne "") { @PlotDefs {"fontsize"}  = $fontsize ; }
      if ($width      ne "") { @PlotDefs {"width"}     = $width ; }
      if ($align      ne "") { @PlotDefs {"align"}     = $align ; }
      if ($shift      ne "") { @PlotDefs {"shift"}     = $shift ; }
      if ($mark       ne "") { @PlotDefs {"mark"}      = $mark ; }
      if ($markcolor  ne "") { @PlotDefs {"markcolor"} = $markcolor ; }
#     if ($link       ne "") { @PlotDefs {"link"}      = $link ; }
#     if ($hint       ne "") { @PlotDefs {"hint"}      = $hint ; }
      &GetData ; next PlotData ;
    }

    if ($bar eq "")
    {
      if ($prevbar ne "")
      { $bar = $prevbar ; }
      else
      {
        if ($BarsCommandFound)
        {
          if ($#Bars > 0)
          { &Error ("PlotData invalid. Specify attribute 'bar'.") ;
            &GetData ; next PlotData ; }
          elsif ($#Bars == 0)
          {
            $bar = @Bars [0] ;
            &Info ($data, "PlotData incomplete. Attribute 'bar' missing, value '" . @Bars [0] . "' assumed.") ;
          }
          else
          { $bar = "1" ; }
        }
        else
        {
          if ($#Bars > 0)
          { &Error ("PlotData invalid. Attribute 'bar' missing.") ;
            &GetData ; next PlotData ; }
          elsif ($#Bars == 0)
          {
            $bar = @Bars [0] ;
            &Info ($data, "PlotData incomplete. Attribute 'bar' missing, value '" . @Bars [0] . "' assumed.") ;
          }
          else { $bar = "1" ; }
        }
        $prevbar = $bar ;
      }
    }

    if (($at ne "") && (($from ne "") || ($till ne "")))
    { &Error ("PlotData invalid. Attributes 'at' and 'from/till' are mutually exclusive.") ;
      &GetData ; next PlotData ; }

    if ((($from eq "") && ($till ne "")) || (($from ne "") && ($till eq "")))
    { &Error ("PlotData invalid. Specify attribute 'at' or 'from' + 'till'.") ;
      &GetData ; next PlotData ; }


    if ($at ne "")
    {
      if ($text ne "")
      {
        if ($align eq "")
        { &Error ("PlotData invalid. Attribute 'align' missing.") ;
          &GetData ; next PlotData ; }
        if ($fontsize eq "")
        { &Error ("PlotData invalid. Attribute '[font]size' missing.") ;
          &GetData ; next PlotData ; }
        if ($text eq "")
        { &Error ("PlotData invalid. Attribute 'text' missing.") ;
          &GetData ; next PlotData ; }
      }
    }
    else
    {
      if ($color eq "")
      { &Error ("PlotData invalid. Attribute 'color' missing.") ;
        &GetData ; next PlotData ; }
      if ($width eq "")
      { &Error ("PlotData invalid. Attribute 'width' missing.") ;
        &GetData ; next PlotData ; }
    }

    if ($from ne "")
    {
      if (($link ne "") || ($hint ne ""))
      { $MapPNG = $True ; }
      if ($link ne "")
      { $MapSVG = $True ; }

      push @PlotBars, sprintf ("%6.3f,%s,%s,%s,%s,%s,%s,\n", $width, $bar, $from, $till, lc ($color),$link,$hint) ;
      if ($width > @BarWidths {$bar})
      { @BarWidths {$bar} = $width ; }

      if ($text ne "")
      { $at = &DateMedium ($from, $till) ; }

      if ($mark ne "")
      {
        push @PlotLines, sprintf ("%s,%s,%s,%s,,,\n", $bar, $from, $from, lc ($markcolor)) ;
        push @PlotLines, sprintf ("%s,%s,%s,%s,,,\n", $bar, $till, $till, lc ($markcolor)) ;
        $mark = "" ;
      }
    }

    if ($at ne "")
    {
      if ($mark ne "")
      { push @PlotLines, sprintf ("%s,%s,%s,%s,,,\n", $bar, $at, $at, lc ($markcolor)) ; }

      if ($text ne "")
      {
        my $textdetails = "" ;

        if ($link ne "")
        {
          if ($text =~ /\[.*\]/)
          {
            &Warning ("PlotData contains implicit link(s) in attribute 'text' and explicit attribute 'link'. " .
                      "Implicit link(s) ignored.") ;
            $text =~ s/\[+ (?:[^\|]* \|)? ([^\]]*) \]+/$1/gx ;
          }
        }

        if ($align eq "")
        { $align = "center" ; }
        if ($color eq "")
        { $color = "black" ; }
        if ($fontsize eq "")
        { $fontsize = "S" ; }
        if ($adjust eq "")
        { $adjust = "0,0" ; }

#        $textdetails = "  textdetails: align=$align size=$size"  ;
#        if ($textcolor eq "")
#        { $textcolor = "black" ; }
#        if ($color ne "")
#        { $textdetails .= " color=$textcolor" ; }

        my ($xpos, $ypos) ;
        my $barcnt = 0 ;
        for ($b = 0 ; $b <= $#Bars ; $b++)
        {
          if (lc(@Bars [$b]) eq lc($bar))
          { $barcnt = ($b + 1) ; last ; }
        }

        if (@Axis {"time"} eq "x")
        { $xpos = "$at(s)" ; $ypos = "[$barcnt](s)" ; }
        else
        { $ypos = "$at(s)" ; $xpos = "[$barcnt](s)" ; }

        if ($shift ne "")
        {
          my ($shiftx, $shifty) = split (",", $shift) ;
          if ($shiftx > 0)
          { $xpos .= "+$shiftx" ; }
          if ($shiftx < 0)
          { $xpos .= "$shiftx" ; }
          if ($shifty > 0)
          { $ypos .= "+$shifty" ; }
          if ($shifty < 0)
          { $ypos .= "$shifty" ; }
        }
        my $tabs = "" ;

        &WriteText ("~", $xpos, $ypos, $text, $textcolor, $fontsize, $align, $link, $hint) ;
      }
    }

    &GetData ;
  }

  if ((! $BarsCommandFound) && ($#Bars > 1))
  { &Info2 ("PlotBars definition: no (valid) command 'BarData' found in previous lines. Bars will presented in order of appearance in PlotData.") ; }

  $maxwidth = 0 ;
  foreach $key (keys %BarWidths)
  {
    if (@BarWidths {$key} == 0)
    { &Warning ($data, "PlotData incomplete. No bar width defined for bar '$key', assume width from widest bar (used for line marks).") ; }
    elsif (@BarWidths {$key} > $maxwidth)
    { $maxwidth = @BarWidths {$key} ; }
  }
  foreach $key (keys %BarWidths)
  {
    if (@BarWidths {$key} == 0)
    { @BarWidths {$key} = $maxwidth ; }
  }
}

sub ParseScale
{
  my ($scale) ;

  if ($Command =~ /ScaleMajor/i)
  { $scale .= 'Major' ; }
  else
  { $scale .= 'Minor' ; }

  if (! ValidAttributes ("Scale" . $scale)) { return ; }

  @Scales {$scale} = $True ;

  foreach $attribute (keys %Attributes)
  {
    my $attrvalue = @Attributes {$attribute} ;

    if ($attribute =~ /Grid/i) # preferred gridcolor instead of grid, grid allowed for compatability
    {
      if ((! &ColorPredefined ($attrvalue)) && (! defined (@Colors {lc ($attrvalue)})))
      { &Error ("Scale attribute '$attribute' invalid. Unknown color '$attrvalue'.") ;  return ; }
      @Attributes {$scale . " grid"} = $attrvalue ;
      delete (@Attributes {"grid"}) ;
    }
    elsif ($attribute =~ /Unit/i)
    {
      if ($DateFormat eq "yyyy")
      {
        if (! ($attrvalue =~ /^year|years$/i))
        { &Error ("Scale attribute '$attribute' invalid. DateFormat 'yyyy' implies 'unit:year'.") ;  return ; }
      }
      else
      {
        if (! ($attrvalue =~ /^(?:year|month|day)s?$/i))
        { &Error ("Scale attribute '$attribute' invalid. Specify year, month or day.") ;  return ; }
      }
      $attrvalue =~ s/s$// ;
      @Attributes {$scale . " unit"} = $attrvalue ;
      delete (@Attributes {"unit"}) ;
    }
    elsif ($attribute =~ /Increment/i)
    {
      if ((! ($attrvalue =~ /^\d+$/i)) || ($attrvalue == 0))
      { &Error ("Scale attribute '$attribute' invalid. Specify positive integer.") ;  return ; }
      @Attributes {$scale . " inc"} = $attrvalue ;
      delete (@Attributes {"increment"}) ;
    }
    elsif ($attribute =~ /Start/i)
    {
      if (! (defined ($DateFormat)))
      { &Error ("Scale attribute '$attribute' invalid.\n" .
                "No (valid) command 'DateFormat' specified in previous lines.") ;  return ; }

      if (! &ValidDateFormat ($attrvalue))
      { &Error ("Scale attribute '$attribute' invalid.\n" .
                "Date does not conform to specified DateFormat '$DateFormat'.") ;  return ; }

      if (($DateFormat =~ /\d\d\/\d\d\/\d\d\d\d/) && (substr ($attrvalue,6,4) < 1800))
      { &Error ("Scale attribute '$attribute' invalid.\n" .
                " Specify year >= 1800.") ;  return ; }

      if (! &ValidDateRange ($attrvalue))
      { &Error ("Scale attribute '$attribute' invalid.\n" .
                "Date '$attrvalue' not within range as specified by command DateFormat.") ; return ; }

      @Attributes {$scale . " start"} = $attrvalue ;
      delete (@Attributes {"start"}) ;
    }
    if ($DateFormat eq "yyyy") { @Attributes {$scale . " unit"} = "year" ; }
  }
  foreach $attribute (keys %Attributes)
  { @Scales {$attribute} = @Attributes {$attribute} ; }
}

sub ParseTextData
{
  &GetData ;
  if ($NoData)
  { &Error ("Data expected for command 'TextData', but line is not indented.\n") ; return ; }

  my ($pos, $tabs, $fontsize, $lineheight, $textcolor, $text, $link, $hint) ;

  TextData:
  while ((! $InputParsed) && (! $NoData))
  {
    if (! &ValidAttributes ("TextData"))
    { &GetData ; next ;}

    $pos = "" ; $tabs = "" ; $fontsize = "" ; $lineheight = "" ; $textcolor = "" ; $link = "" ; $hint = "" ;

    if (defined (@TextDefs {"tabs"}))       { $tabs       = @TextDefs {"tabs"} ; }
    if (defined (@TextDefs {"fontsize"}))   { $fontsize   = @TextDefs {"fontsize"} ; }
    if (defined (@TextDefs {"lineheight"})) { $lineheight = @TextDefs {"lineheight"} ; }
    if (defined (@TextDefs {"textcolor"}))  { $textcolor  = @TextDefs {"textcolor"} ; }

    my $data2 = $data ;
    ($data2, $text) = &ExtractText ($data2) ;
    @Attributes = split (" ", $data2) ;

    foreach $attribute (keys %Attributes)
    {
      my $attrvalue = @Attributes {$attribute} ;

      if ($attribute =~ /^FontSize$/i)
      {
        if (($attrvalue !~ /\d+(?:\.\d)?/) && ($attrvalue !~ /^(?:xs|s|m|l|xl)$/i))
        { &Error ("TextData invalid. Attribute '$attribute': specify number of XS,S,M,L,XL.") ;
          &GetData ; next TextData ; }

        $fontsize = $attrvalue ;

        if ($fontsize !~ /^(?:xs|s|m|l|xl)$/i)
        {
          if ($fontsize < 6)
          { &Warning ("TextData attribute 'fontsize' value too low. Font size 6 assumed.\n") ;
            $fontsize = 6 ; }
          if ($fontsize > 30)
          { &Warning ("TextData attribute 'fontsize' value too high. Font size 30 assumed.\n") ;
            $fontsize = 30 ; }
        }
      }
      elsif ($attribute =~ /^LineHeight$/i)
      {
        $lineheight = &Normalize ($attrvalue) ;
        if (($lineheight < -0.4) || ($lineheight > 0.4))
        {
          if (! $bypass)
          { &Error ("TextData attribute 'lineheight' invalid.\n" .
                    "Specify value up to 40 pixels = 0.4 inch\n" .
                    "Run with option -b (bypass checks) when this is correct.\n") ; }
        }
      }
      elsif ($attribute =~ /^Pos$/i)
      {
        $attrvalue =~ s/\s*$hBrO (.*) $hBrC\s*/$1/x ;
        ($posx,$posy) = split (",", $attrvalue) ;
        $posx = &Normalize ($posx) ;
        $posy = &Normalize ($posy) ;
        $pos = "$posx,$posy" ;
      }
      elsif ($attribute =~ /^Tabs$/i)
      {
        $tabs = $attrvalue ;
      }
      elsif ($attribute =~ /^Color$/i)
      {
        $textcolor = $attrvalue ;
      }
      elsif ($attribute =~ /^Text$/i)
      {
        $text = $attrvalue ;
        $text =~ s/\\n/~/gs ;
        if ($text =~ /\~/)
        { &Warning ("TextData attribute 'text' contains ~ (tilde).\n" .
                    "Tilde will not be translated into newline character (only in PlotData)") ; }

      }
      elsif ($attribute =~ /^Link$/i)
      {
        $link = &ParseText ($attrvalue) ;
        $link = &EncodeURL (&NormalizeURL ($link)) ;
      }
    }

    if ($fontsize eq "")
    { $fontsize = "S" ; }

    if ($lineheight eq "")
    {
      if ($fontsize =~ /XS|S|M|L|XL/i)
      {
        if     ($fontsize =~ /XS/i) { $lineheight = 0.11 ; }
        elsif  ($fontsize =~ /S/i)  { $lineheight = 0.13 ; }
        elsif  ($fontsize =~ /M/i)  { $lineheight = 0.155 ; }
        elsif  ($fontsize =~ /XL/i) { $lineheight = 0.24 ; }
        else                        { $lineheight = 0.19 ; }
      }
      else
      {
        $lineheight = sprintf ("%.2f", (($fontsize * 1.2) / 100)) ;
        if ($lineheight < $fontsize/100 + 0.02)
        { $lineheight = $fontsize/100 + 0.02 ; }
      }
    }

    if ($textcolor eq "")
    { $textcolor = "black" ; }

    if ($pos eq "")
    {
      $pos = @TextDefs {"pos"} ;
      ($posx,$posy) = split (",", $pos) ;
      $posy -= $lineheight ;
      $pos = "$posx,$posy" ;
      @TextDefs {"pos"} = $pos ;
    }

#    if ($link ne "")
#    { ($text, $link, $hint) = &ProcessWikiLink ($text, $link, $hint) ; }

    if ($text eq "") # upd defaults
    {
      if ($pos        ne "") { @TextDefs {"pos"}        = $pos ; }
      if ($tabs       ne "") { @TextDefs {"tabs"}       = $tabs ; }
      if ($fontsize   ne "") { @TextDefs {"fontsize"}   = $fontsize ; }
      if ($textcolor  ne "") { @TextDefs {"textcolor"}  = $textcolor ; }
      if ($lineheight ne "") { @TextDefs {"lineheight"} = $lineheight ; }
      &GetData ; next TextData ;
    }

    if ($link ne "")
    {
      if ($text =~ /\[.*\]/)
      {
        &Warning ("TextData contains implicit link(s) in attribute 'text' and explicit attribute 'link'.\n" .
                  "Implicit link(s) ignored.") ;
        $text =~ s/\[+ (?:[^\|]* \|)? ([^\]]*) \]+/$1/gx ;
      }
    }

    if ($text =~ /\[ [^\]]* \^ [^\]]* \]/x)
    {
      &Warning ("TextData attribute 'text' contains tab character (^) inside implicit link ([[..]]). Tab ignored.") ;
      $text =~ s/(\[+ [^\]]* \^ [^\]]* \]+)/($a = $1), ($a =~ s+\^+ +g), $a/gxe ;
    }

    if (defined ($tabs) && ($tabs ne ""))
    {
      $tabs =~ s/^\s*$hBrO (.*) $hBrC\s*$/$1/x ;
      @Tabs = split (",", $tabs) ;
      foreach $tab (@Tabs)
      {
        $tab =~ s/\s* (.*) \s*$/$1/x ;
        if (! ($tab =~ /\d+\-(?:center|left|right)$/))
        { &Error ("Specify attribute 'tabs' as 'n-a,n-a,n-a,.. where n = numeric value, a = left|right|center.") ;
          while ((! $InputParsed) && (! $NoData)) { &GetData ; } return ; }
      }

      @Text = split ('\^', $text) ;
      if ($#Text > $#Tabs + 1)
      { &Error ("TextData invalid. " . $#Text . " tab characters ('^') in text, only " . ($#Tabs+1) . " tab(s) defined.") ;
        &GetData ; next TextData ; }
    }

    &WriteText ("^", $posx, $posy, $text, $textcolor, $fontsize, "left", $link, $hint, $tabs) ;

    &GetData ;
  }
}

sub ParseTimeAxis
{
  if (! &ValidAttributes ("TimeAxis")) { return ; }

  foreach $attribute (keys %Attributes)
  {
    my $attrvalue = @Attributes {$attribute} ;

    if ($attribute =~ /Format/i)
    {
      if ($DateFormat eq "yyyy")
      {
        if (! ($attrvalue =~ /^(?:yy|yyyy)$/i))
        { &Error ("TimeAxis attribute '$attribute' invalid.\n" .
                  "DateFormat 'yyyy' implies 'format:yy' or 'format:yyyy'.") ;  return ; }
      }
    }

    elsif ($attribute =~ /Orientation/i)
    {
      if ($attrvalue =~ /^hor(?:izontal)?$/i)
      { @Attributes {"time"} = "x" ; }
      elsif ($attrvalue =~ /^ver(?:tical)?$/i)
      { @Attributes {"time"} = "y" ; }
      else
      { &Error ("TimeAxis attribute '$attribute' invalid.\n" .
                "Specify hor[izontal] or ver[tical]") ;  return ; }
      delete (@Attributes {"orientation"}) ;
    }
  }

  if (! defined (@Attributes {"format"}))
  {
    &Info ("TimeAxis attribute 'format' not specified. Value 'yyyy' assumed") ;
    @Attributes {"dateformat"} = "yyyy" ;
  }

  %Axis = %Attributes ;
}

sub ParseUnknownCommand
{
  $name = $Command ;
  $name =~ s/[^a-zA-Z].*$// ;
  &Error ("Command '$name' unknown.") ;
}

sub RemoveSpaces
{
  my $text = shift ;
  $text =~ s/\s//g ;
  return ($text) ;
}

sub DetectMissingCommands
{
  if (! defined (%Image))          { &Error2 ("Command ImageSize missing or invalid") ; }
  if (! defined (%PlotArea))       { &Error2 ("Command PlotArea missing or invalid") ; }
  if (! defined ($DateFormat))     { &Error2 ("Command DateFormat missing or invalid") ; }
  if (! defined (@Axis {"time"}))  { &Error2 ("Command TimeAxis missing or invalid") ; }
}

sub Normalize
{

  my $number    = shift ;
  my $reference = shift ;
  my ($val, $dim) ;
  $val = $number ; $val =~ s/[^\d\.\-].*$//g ;
  $dim = $number ; $dim =~ s/\d//g ;
  if    ($dim =~ /in/i) { $number = $val ; }
  elsif ($dim =~ /cm/i) { $number = $val / 2.54 ; }
  elsif ($dim =~ /%/)   { $number = $reference * $val / 100 ; }
  else                  { $number = $val / 100 ; }
  return (sprintf ("%.3f", $number)) ;
}

sub NormalizeDimensions
{
  my ($val, $dim) ;

  @Image    {"width"}  = &Normalize (@Image    {"width"}) ;
  @Image    {"height"} = &Normalize (@Image    {"height"}) ;
  @PlotArea {"width"}  = &Normalize (@PlotArea {"width"},  @Image {"width"}) ;
  @PlotArea {"height"} = &Normalize (@PlotArea {"height"}, @Image {"height"}) ;
  @PlotArea {"left"}   = &Normalize (@PlotArea {"left"},   @Image {"width"}) ;
  @PlotArea {"bottom"} = &Normalize (@PlotArea {"bottom"}, @Image {"height"}) ;

  if ((@Image {"width"} > 16) || (@Image {"height"} > 12))
  {
    if (! $bypass)
    {
      &Error ("Maximum image size is 1600x1200 pixels = 16x12 inch\n" .
              "  Run with option -b (bypass checks) when this is correct.\n") ;
      return ;
    }
  }
  if ((@Image {"width"} < 1) || (@Image {"height"} < 1))
  {
    &Error ("Minimum image size is 25x25 pixels = 0.25x0.25 inch\n") ;
    return ;
  }

  if (@PlotArea {"width"} > @Image {"width"})
  { &Warning2 ("Plot width larger than image width. Plot area adjusted.\n") ;
    @PlotArea {"width"} = @Image {"width"} ; }

  if (@PlotArea {"width"} < 0.2)
  { &Warning2 ("Plot width less than 20 pixels = 0.2 inch. Plot area adjusted.\n") ;
    @PlotArea {"width"} = 0.2 ; }

  if (@PlotArea {"height"} > @Image {"height"})
  { &Warning2 ("Plot height larger than image height. Plot area adjusted.\n") ;
    @PlotArea {"height"} = @Image {"height"} ; }

  if (@PlotArea {"height"} < 0.2)
  { &Warning2 ("Plot height less than 20 pixels = 0.2 inch. Plot area adjusted.\n") ;
    @PlotArea {"height"} = 0.2 ; }

  if (@PlotArea {"left"} + @PlotArea {"width"} > @Image {"width"})
  { &Warning2 ("Plot width + margin larger than image width. Plot area adjusted.\n") ;
    @PlotArea {"left"} = @Image {"width"} - @PlotArea {"width"} ; }

  if (@PlotArea {"left"} < 0)
  { @PlotArea {"left"} = 0 ; }

  if (@PlotArea {"bottom"} + @PlotArea {"height"} > @Image {"height"})
  { &Warning2 ("Plot height + margin larger than image height. Plot area adjusted.\n") ;
    @PlotArea {"bottom"} = @Image {"height"} - @PlotArea {"height"} ; }

  if (@PlotArea {"bottom"} < 0)
  { @PlotArea {"bottom"} = 0 ; }

  if (defined (@Legend {"orientation"}))
  {
    if (defined (@Legend {"left"}))
    { @Legend {"left"} = &Normalize (@Legend {"left"},        @Image {"width"}) ; }
    if (defined (@Legend {"top"}))
    { @Legend {"top"}  = &Normalize (@Legend {"top"},         @Image {"height"}) ; }
    if (defined (@Legend {"columnwidth"}))
    { @Legend {"columnwidth"} = &Normalize (@Legend {"columnwidth"}, @Image {"width"}) ; }

    if (! defined (@Legend {"columns"}))
    {
      @Legend {"columns"} = 1 ;
      if ((@Legend {"orientation"} =~ /ver/i) &&
          (@Legend {"position"} =~ /top|bottom/i))
      {
        if ($#LegendData > 10)
        {
          @Legend {"columns"} = 3 ;
          &Info2 ("Legend attribute 'columns' not defined. 3 columns assumed.") ;
        }
        elsif ($#LegendData >  5)
        {
          @Legend {"columns"} = 2 ;
          &Info2 ("Legend attribute 'columns' not defined. 2 columns assumed.") ;
        }
      }
    }

    if (@Legend {"position"} =~ /top/i)
    {
      if (! defined (@Legend {"left"}))
      { @Legend {"left"} = @PlotArea {"left"} ; }
      if (! defined (@Legend {"top"}))
      { @Legend {"top"} = (@Image {"height"} - 0.2) ; }
      if ((! defined (@Legend {"columnwidth"}))  && (@Legend {"columns"} > 1))
      { @Legend {"columnwidth"} = sprintf ("%02f", ((@PlotArea {"left"} + @PlotArea {"width"} - 0.2) / @Legend {"columns"})) ; }
    }
    elsif (@Legend {"position"} =~ /bottom/i)
    {
      if (! defined (@Legend {"left"}))
      { @Legend {"left"} = @PlotArea {"left"} ; }
      if (! defined (@Legend {"top"}))
      { @Legend {"top"} = (@PlotArea {"bottom"} - 0.4) ; }
      if ((! defined (@Legend {"columnwidth"}))  && (@Legend {"columns"} > 1))
      { @Legend {"columnwidth"} = sprintf ("%02f", ((@PlotArea {"left"} + @PlotArea {"width"} - 0.2) / @Legend {"columns"})) ; }
    }
    elsif (@Legend {"position"} =~ /right/i)
    {
      if (! defined (@Legend {"left"}))
      { @Legend {"left"} = (@PlotArea {"left"} + @PlotArea {"width"} + 0.2) ; }
      if (! defined (@Legend {"top"}))
      { @Legend {"top"} = (@PlotArea {"bottom"} + @PlotArea {"height"} - 0.2) ; }
    }
  }


}

sub WriteProcAnnotate
{
  my $xpos        = shift ;
  my $ypos        = shift ;
  my $text        = shift ;
  my $textcolor   = shift ;
  my $fontsize    = shift ;
  my $align       = shift ;
  my $link        = shift ;
  my $hint        = shift ;

  if ($textcolor eq "")
  { $textcolor = "black" ; }

  my $textdetails = "  textdetails: align=$align size=$fontsize color=$textcolor"  ;

  push @PlotTextsPng, "#proc annotate\n" ;
  push @PlotTextsSvg, "#proc annotate\n" ;

  push @PlotTextsPng, "  location: $xpos $ypos\n" ;
  push @PlotTextsSvg, "  location: $xpos $ypos\n" ;

  push @PlotTextsPng, $textdetails . "\n" ;
  push @PlotTextsSvg, $textdetails . "\n" ;

  $text2 = $text ;
  $text2 =~ s/\[\[//g ;
  $text2 =~ s/\]\]//g ;
  if ($text2 =~ /^\s/)
  { push @PlotTextsPng, "  text: \n\\$text2\n\n"  ; }
  else
  { push @PlotTextsPng, "  text: $text2\n\n"  ; }

  $text2 = $text ;
  if ($link ne "")
  {
    # put placeholder in Ploticus input file
    # will be replaced by real link after SVG generation
    # this allows adding color info
    push @linksSVG, $link ;
    my $lcnt = $#linksSVG ;
    $text2 =~ s/\[\[ ([^\]]+) \]\]/\[$lcnt\[$1\]$lcnt\]/x ;
    $text2 =~ s/\[\[ ([^\]]+) $/\[$lcnt\[$1\]$lcnt\]/x ;
    $text2 =~ s/^ ([^\[]+) \]\]/\[$lcnt\[$1\]$lcnt\]/x ;
  }

  $text3 = &EncodeHtml ($text2) ;
  if ($text2 ne $text3)
  {
    # put placeholder in Ploticus input file
    # will be replaced by real text after SVG generation
    # Ploticus would autoscale image improperly when text contains &#xxx; tags
    # because this would count as 5 chars
    push @textsSVG, $text3 ;
    $text3 = "{{" . $#textsSVG . "}}" ;
    while (length ($text3) < length ($text2)) { $text3 .= "x" ; }
  }

  if ($text3 =~ /^\s/)
  { push @PlotTextsSvg, "  text: \n\\$text3\n\n"  ; }
  else
  { push @PlotTextsSvg, "  text: $text3\n\n"  ; }

  if ($link ne "")
  {
    $MapPNG = $True ;

    push @PlotTextsPng, "#proc annotate\n" ;
    push @PlotTextsPng, "  location: $xpos $ypos\n" ;

    if ($align ne "right")
    {
      push @PlotTextsPng, "  clickmapurl: $link\n" ;
      if ($hint ne "")
      { push @PlotTextsPng, "  clickmaplabel: $hint\n" ; }
    }
    else
    {
      if ($WarnOnRightAlignedText ++ == 0)
      { &Warning2 ("Links on right aligned texts are only supported for svg output,\npending Ploticus bug fix.") ; }
      return ;
    }

    $textdetails =~ s/color=[^\s]+/color=$LinkColor/ ;
    push @PlotTextsPng, $textdetails . "\n" ;

    if ($text =~ /^[^\[]+\]\]/)
    { $text = "[[" . $text ; }
    if ($text =~ /\[\[[^\]]+$/)
    { $text .= "]]"  ; }
    my $pos1 = index ($text, "[[") ;
    my $pos2 = index ($text, "]]") + 1 ;
    if (($pos1 > -1) && ($pos2 > -1))
    {
      for (my $i = 0 ; $i < length ($text) ; $i++)
      {
        $c = substr ($text, $i, 1) ;
        if ($c ne "\n")
        {
          if (($i < $pos1) || ($i > $pos2))
          { substr ($text, $i, 1) = " " ; }
        }
      }
    }

    $text =~ s/\[\[(.*?)\]\]/$1/s ;

    if ($text =~ /^\s/)
    { push @PlotTextsPng, "  text: \n\\$text\n\n"  ; }
    else
    { push @PlotTextsPng, "  text: $text\n\n"  ; }

#    push @PlotTextsPng, "#proc rect\n" ;
#    push @PlotTextsPng, "  color: green\n" ;
#    push @PlotTextsPng, "  rectangle: 1(s)+0.25 1937.500(s)+0.06 1(s)+0.50 1937.500(s)+0.058\n" ;
#    push @PlotTextsPng, "\n\n" ;
  }
}

sub WriteText
{
  my $mode      = shift ;
  my $posx      = shift ;
  my $posy      = shift ;
  my $text      = shift ;
  my $textcolor = shift ;
  my $fontsize  = shift ;
  my $align     = shift ;
  my $link      = shift ;
  my $hint      = shift ;
  my $tabs      = shift ;
  my ($link2, $hint2, $tab) ;

  if ((($posx !~ /(s)/) && (($posx < 0) || ($posx > @Image {"width"}/100))) ||
      (($posy !~ /(s)/) && (($posy < 0) || ($posy > @Image {"height"}/100))))
  {
    if ($WarnTextOutsideArea++ < 5)
    { $text =~ s/\n/~/g ;
      &Warning ("Text segment '$text' falls outside image area. Text ignored.") ; }
    return ;
  }

  my @Tabs = split (",", $tabs) ;
  foreach $tab (@Tabs)
  { $tab =~ s/\s* (.*) \s*$/$1/x ; }

  $posx0 = $posx ;
  my @Text ;
  my $dy = 0 ;

  if ($text =~ /\[\[.*\]\]/)
  {
    $link = "" ; $hint = "" ;
  }

  my @Text ;
  if ($mode eq "^")
  { @Text = split ('\^', $text) ; }
  elsif ($mode eq "~")
  {
    @Text = split ('\n', $text) ;

    if ($fontsize =~ /XS|S|M|L|XL/i)
    {
      if     ($fontsize =~ /XS/i) { $dy = 0.09 ; }
      elsif  ($fontsize =~ /S/i)  { $dy = 0.11 ; }
      elsif  ($fontsize =~ /M/i)  { $dy = 0.135 ; }
      elsif  ($fontsize =~ /XL/i) { $dy = 0.21 ; }
      else                        { $dy = 0.16 ; }
    }
    else
    {
      $dy = sprintf ("%.2f", (($fontsize * 1.2) / 100)) ;
      if ($dy < $fontsize/100 + 0.02)
      { $dy = $fontsize/100 + 0.02 ; }
    }
  }
  else
  { push @Text, $text ; }


  foreach $text (@Text)
  {
    if ($text !~ /^[\n\s]*$/)
    {
    if ($text =~ /http/)
    { $a = 1 ; }
      $link2 = "" ;
      $hint2 = "" ;
      ($text, $link2, $hint2) = &ProcessWikiLink ($text, $link2, $hint2) ;

      if ($link2 eq "")
      {
        $link2 = $link ;
        if (($link ne "") && ($text !~ /\[\[.*\]\]/))
        { $text = "[[" . $text . "]]" ;}
      }
      if ($hint2 eq "")
      { $hint2 = $hint ; }

      &WriteProcAnnotate ($posx, $posy, $text, $textcolor, $fontsize, $align, $link2, $hint2) ;
    }

    if ($#Tabs >= 0)
    {
      $tab = shift (@Tabs) ;
      ($dx,$align) = split ("\-", $tab) ;
      $posx = $posx0 + &Normalize ($dx) ;
    }
    if ($posy =~ /\+/)
    { ($posy1, $posy2) = split ('\+', $posy) ; }
    elsif ($posy =~ /\-/)
    { ($posy1, $posy2) = split ('\-', $posy) ; $posy2 = -$posy2 ; }
    else
    { $posy1 = $posy ; $posy2 = 0 ; }

    $posy2 -= $dy ;

    if ($posy2 == 0)
    { $posy = $posy1 ; }
    elsif ($posy2 < 0)
    { $posy = $posy1 . "$posy2" ; }
    else
    { $posy = $posy1 . "+" . $posy2 ; }
  }
}

sub WriteProcDrawCommandsOld
{
  my $posx      = shift ;
  my $posy      = shift ;
  my $text      = shift ;
  my $textcolor = shift ;
  my $fontsize  = shift ;
  my $link      = shift ;
  my $hint      = shift ;

  $posx0 = $posx ;
  my @Text = split ('\^', $text) ;
  my $align = "text" ;
  foreach $text (@Text)
  {
    push @TextData, "  mov $posx $posy\n" ;
    push @TextData, "  textsize $fontsize\n" ;
    push @TextData, "  color $textcolor\n" ;
    push @TextData, "  $align $text\n" ;


    $tab = shift (@Tabs) ;
    ($dx,$align) = split ("\-", $tab) ;
    $posx = $posx0 + &Normalize ($dx) ;
    if    ($align =~ /left/i)  { $align = "text" ; }
    elsif ($align =~ /right/i) { $align = "rightjust" ; }
    else                       { $align = "centext" ; }
  }
}

sub WritePlotFile
{
  $script = "" ;
  my ($color) ;
  if (@Axis {"time"} eq "x")
  { $AxisBars = "y" ; }
  else
  { $AxisBars = "x" ; }

  $file_script = $tmpdir.$pathseparator."EasyTimeline.txt.$$" ;
  print "file_script = ".$file_script."<br>\n";
# $fmt = "gif" ;
  open "FILE_OUT", ">", $file_script ;

  #proc settings
  $script .= "#proc settings\n" ;
  $script .= "  xml_encoding: utf-8\n" ;
  $script .= "\n" ;

  # proc page
  $script .= "#proc page\n" ;
  $script .= "  dopagebox: no\n" ;
  $script .= "  pagesize: ". @Image {"width"} . " ". @Image {"height"} . "\n" ;
  if (defined (@BackgroundColors {"canvas"}))
  { $script .= "  backgroundcolor: " . @BackgroundColors {"canvas"} . "\n" ; }
  $script .= "\n" ;

  $barcnt = $#Bars + 1 ;

# note: x,y do not refer to vertical/horizontal axis, just variables
# first bar plotted at 1
# last bar plotted at c
# C = c - 1 (units between centers of lowest and highest bar)
# P = plotwidth in pixels
# y = plotwidth in units
# B = half bar width
#
# Justify:
# axis starts at 1-x and ends at c + x =
# x * P/y = B -> x = By / P
#
# y = c + x - (1 - x) = (c-1) + 2x -> x = (y - (c-1) ) / 2
# x = By / P = (y - C) / 2 ->
# 2By / P = y - C ->
# 2By = Py - PC ->
# y (2B - P) = - PC ->
# y = -PC / (2B - P)
# x = (y - C) / 2
# axis runs from 1-x to c+x
  if (! defined ($AlignBars))
  {
    &Info2 ("AlignBars not defined. Alignment 'early' assumed.") ;
    $AlignBars = "early" ;
  }

  if (@Axis {"time"} eq "x")
  { $extent = "height" ; }
  else
  { $extent = "width" ; }

  if ($MaxBarWidth > @PlotArea {$extent})
  { &Error2 ("Maximum bar width exceeds plotarea " . $extent . ".") ; return ; }

  if ($MaxBarWidth == @PlotArea {$extent})
  {
    $till = 1 ;
    $from = 1 ;
  }
  else
  {
    if ($AlignBars eq "justify")
    {
      $y = - (@PlotArea {$extent} * $#Bars)  / ($MaxBarWidth - @PlotArea {$extent}) ;
      $x = ($y - $#Bars) / 2 ;
      $from = 1 - $x ;
      $till = 1 + $#Bars + $x ;
    }
    elsif ($AlignBars eq "early")
    {
      $y = $#Bars + 1 ;
      $x = (($MaxBarWidth /2) * $y) / @PlotArea {$extent} ;
      $from = 1 - $x ;
      $till = $from + $y ;
    }
    elsif ($AlignBars eq "late")
    {
      $y = $#Bars + 1 ;
      $x = (($MaxBarWidth /2) * $y) / @PlotArea {$extent} ;
      $till = $y + $x ;
      $from = $till - $y ;
    }
  }

  if ($#Bars == 0)
  {
    $from = 1 - $MaxBarWidth ;
    $till = 1 + $MaxBarWidth ;
  }
  elsif ($from eq $till)
  { $till = $from + 1 ; }

  #proc areadef
  $script .= "#proc areadef\n" ;
  $script .= "  rectangle: " . @PlotArea {"left"} . " " . @PlotArea {"bottom"} . " " .
                   sprintf ("%.2f", @PlotArea {"left"} + @PlotArea {"width"}). " " . sprintf ("%.2f", @PlotArea {"bottom"} + @PlotArea {"height"}) . "\n" ;
  if (($DateFormat eq "yyyy") || ($DateFormat eq "x.y"))
  { $script .= "  " . @Axis {"time"} . "scaletype: linear\n" ; } # date yyyy
  else
  { $script .= "  " . @Axis {"time"} . "scaletype: date $DateFormat\n" ; }
  $script .= "  " . @Axis {"time"} . "range: " . @Period{"from"} . " " . @Period{"till"} . "\n" ;
  $script .= "  " . $AxisBars . "scaletype: linear\n" ;
  $script .= "  " . $AxisBars . "range: " . sprintf ("%.3f", $from) . " " . sprintf ("%.3f", $till) . "\n" ;
  $script .= "\n" ;

  #proc rect (test)
#  $script .= "#proc rect\n" ;
#  $script .= "  rectangle 1.0 1.0 1.4 1.4\n" ;
#  $script .= "  color gray(0.95)\n" ;
#  $script .= "  clickmaplabel: Vladimir Ilyich Lenin\n" ;
#  $script .= "  clickmapurl: http://www.wikipedia.org/wiki/Vladimir_Lenin\n" ;


  #proc legendentry
  foreach $color (sort keys %Colors)
  {
    $script .= "#proc legendentry\n" ;
    $script .= "  sampletype: color\n" ;

    if ((defined (@ColorLabels {$color})) && (@ColorLabels {$color} ne ""))
    { $script .= "  label: " . @ColorLabels {$color} . "\n" ; }
    $script .= "  details: " . @Colors {$color} . "\n" ;
    $script .= "  tag: $color\n" ;
    $script .= "\n" ;
  }

  if (defined (@BackgroundColors {"canvas"}))
  {
    #proc getdata / #proc bars
    $script .= "#proc getdata\n" ;
    $script .= "  delim: comma\n" ;
    $script .= "  data:\n" ;

    $maxwidth = 0 ;
    foreach $entry (@PlotBars)
    {
      ($width) = split (",", $entry) ;
      if ($width > $maxwidth)
      { $maxwidth = $width ; }
    }

    for ($b = 0 ; $b <= $#Bars ; $b++)
    { $script .= ($b+1) . "," . @Period {"from"} . "," . @Period {"till"} . ",".
                 @BackgroundColors {"bars"} . "\n" ; }
    $script .= "\n" ;

    #proc bars
    $script .= "#proc bars\n" ;
    $script .= "  axis: " . @Axis {"time"} . "\n" ;
    $script .= "  barwidth: $maxwidth\n" ;
    $script .= "  outline: no\n" ;
    if (@Axis {"time"} eq "x")
    { $script .= "  horizontalbars: yes\n" ; }
    $script .= "  locfield: 1\n" ;
    $script .= "  segmentfields: 2 3\n" ;
    $script .= "  colorfield: 4\n" ;

#   $script .= "  clickmaplabel: Vladimir Ilyich Lenin\n" ;
#   $script .= "  clickmapurl: http://www.wikipedia.org/wiki/Vladimir_Lenin\n" ;

    $script .= "\n" ;
  }

  #proc axis
  if (defined (@Scales {"Minor grid"}))
  { &PlotScale ("Minor", $True) ; }
  if (defined (@Scales {"Major grid"}))
  { &PlotScale ("Major", $True) ; }

  @PlotBarsNow = @PlotBars ;
  &PlotBars ;

  $script .= "\n([inc1])\n\n" ; # will be replace by annotations
  $script .= "\n([inc3])\n\n" ; # will be replace by rects

  foreach $entry (@PlotLines)
  {
   ($bar) = split (",", $entry) ;
   $width = @BarWidths {$bar} ;
   $entry  = sprintf ("%6.3f",$width) . "," . $entry ;
  }
  @PlotBarsNow = @PlotLines ;
  &PlotBars ;

  #proc axis
  if ($#Bars > 0)
  {
    $scriptPng2 = "#proc " . $AxisBars . "axis\n" ;
    $scriptSvg2 = "#proc " . $AxisBars . "axis\n" ;
    if ($AxisBars eq "x")
    {
      $scriptPng2 .= "  stubdetails: adjust=0,0.09\n" ;
      $scriptSvg2 .= "  stubdetails: adjust=0,0.09\n" ;
    }
    else
    {
      $scriptPng2 .= "  stubdetails: adjust=0.09,0\n" ;
      $scriptSvg2 .= "  stubdetails: adjust=0.09,0\n" ;
    }
    $scriptPng2 .= "  tics: none\n" ;
    $scriptSvg2 .= "  tics: none\n" ;
    $scriptPng2 .= "  stubrange: 1\n" ;
    $scriptSvg2 .= "  stubrange: 1\n" ;
    $scriptPng2 .= "  stubslide: -" . sprintf ("%.2f", $MaxBarWidth / 2) . "\n" ;
    $scriptSvg2 .= "  stubslide: -" . sprintf ("%.2f", $MaxBarWidth / 2) . "\n" ;
    $scriptPng2 .= "  stubs: text\n" ;
    $scriptSvg2 .= "  stubs: text\n" ;

    my ($text, $link, $hint) ;

    foreach $bar (@Bars)
    {
      $hint = "" ;
      $text = @BarText {lc ($bar)} ;
      if (! defined ($text))
      { $text = $bar ; }

      $link = @BarLink {lc ($bar)} ;
      if (! defined ($link))
      {
        if ($text =~ /\[.*\]/)
        { ($text, $link, $hint) = &ProcessWikiLink ($text, $link, $hint) ; }
      }

      $text =~ s/\[+([^\]]*)\]+/$1/ ;
      $scriptPng2 .= "$text\n" ;
      if (defined ($link))
      {
        push @linksSVG, $link ;
        my $lcnt = $#linksSVG ;
        $scriptSvg2 .= "[" . $lcnt . "[" . $text . "]" . $lcnt . "]\n" ;
      }
      else
      { $scriptSvg2 .= "$text\n" ; }
    }
    $scriptPng2 .= "\n" ;
    $scriptSvg2 .= "\n" ;

    $scriptPng2 .= "#proc " . $AxisBars . "axis\n" ;
    if ($AxisBars eq "x")
    { $scriptPng2 = "  stubdetails: adjust=0,0.09 color=$linkcolor\n" ; }
    else
    { $scriptPng2 .= "  stubdetails: adjust=0.09,0 color=$linkcolor\n" ; }
    $scriptPng2 .= "  tics: none\n" ;
    $scriptPng2 .= "  stubrange: 1\n" ;
    $scriptPng2 .= "  stubslide: -" . sprintf ("%.2f", $MaxBarWidth / 2) . "\n" ;
    $scriptPng2 .= "  stubs: text\n" ;

    $barcnt = $#Bars + 1 ;
    foreach $bar (@Bars)
    {
      $hint = "" ;
      $text = @BarText {lc ($bar)} ;
      if (! defined ($text))
      { $text = $bar ; }

      $link = @BarLink {lc ($bar)} ;
      if (! defined ($link))
      {
        if ($text =~ /\[.*\]/)
        { ($text, $link, $hint) = &ProcessWikiLink ($text, $link, $hint) ; }
      }
      if ((! defined ($link)) || ($link eq ""))
      { $text = "\\" ; }
      else
      {
        $scriptPng3 .= "#proc rect\n" ;
        $scriptPng3 .= "  rectangle: 0 $barcnt(s)+0.05 " . @PlotArea {"left"} . " $barcnt(s)-0.05\n" ;
        $scriptPng3 .= "  color: " . @BackgroundColors {"canvas"} . "\n" ;
        $scriptPng3 .= "  clickmapurl: " . $link . "\n" ;
        if ((defined ($hint)) && ($hint ne ""))
        { $scriptPng3 .= "  clickmaplabel: " . $hint . "\n" ; }

        $text =~ s/\[+([^\]]*)\]+/$1/ ;
      }
      $scriptPng2 .= "$text\n" ;

      $barcnt-- ;
    }
    $scriptPng2 .= "\n" ;
  }
  $script .= "\n([inc2])\n\n" ;

  if ($#DrawLines >= 0)
  {
    $script .= "#proc drawcommands\n" ;
    $script .= "  commands:\n" ;

#      $script .= "  movp  $at" . "(s) " . @PlotArea {"bottom"} . "\n" ;
#      $script .= "  mark 100 200 symbol 1\n" ;
#      $script .= "  cblock 200 300 205 305 red\n" ;
#      $script .= "  clickmaplabel: Vladimir Ilyich Lenin\n" ;
#      $script .= "  clickmapurl: http://www.wikipedia.org/wiki/Vladimir_Lenin\n" ;

    foreach $entry (@DrawLines)
    {
      chomp ($entry) ;
      ($at, $color) = split (",", $entry) ;
      $script .= "  color $color\n" ;
      $script .= "  width 2\n" ;
      if (@Axis {"time"} eq "x")
      {
        $script .= "  movp  $at" . "(s) " . @PlotArea {"bottom"} . "\n" ;
        $script .= "  linp  $at" . "(s) " . (@PlotArea {"bottom"}+@PlotArea {"height"}) . "\n" ;
      }
      else
      {
        $script .= "  movp  " . @PlotArea {"left"}  . " $at" . "(s)\n" ;
        $script .= "  linp  " . @PlotArea {"width"} . " $at" . "(s)\n" ;
      }
    }
    $script .= "\n" ;
  }

  if ($#PlotTextsPng >= 0)
  {
    foreach $command (@PlotTextsPng)
    {
      if ($command =~ /^\s*location/)
      { $command =~ s/(.*)\[(.*)\](.*)/$1 . ($#Bars - $2 + 2) . $3/xe ; }

      $scriptPng1 .= $command ;
    }
    $scriptPng1 .= "\n" ;
  }

  if ($#PlotTextsSvg >= 0)
  {
    foreach $command (@PlotTextsSvg)
    {
      if ($command =~ /^\s*location/)
      { $command =~ s/(.*)\[(.*)\](.*)/$1 . ($#Bars - $2 + 2) . $3/xe ; }

      $scriptSvg1 .= $command ;
    }
    $scriptSvg1 .= "\n" ;
  }

# $script .= "#proc symbol\n" ;
# $script .= "  location: 01/01/1943(s) Korea \n" ;
# $script .= "  symbol: style=fill shape=downtriangle fillcolor=white radius=0.04\n" ;
# $script .= "\n" ;

  #proc axis
  # repeat without grid to get axis on top of bar
  # needed because axis may overlap bar slightly
  if (defined (@Scales {"Minor"}))
  { &PlotScale ("Minor", $False) ; }
  if (defined (@Scales {"Major"}))
  { &PlotScale ("Major", $False) ; }

  #proc drawcommands
  if ($#TextData >= 0)
  {
    $script .= "#proc drawcommands\n" ;
    $script .= "  commands:\n" ;
    foreach $entry (@TextData)
    { $script .= $entry ; }
    $script .= "\n" ;
  }

  #proc legend
  if (defined (@Legend {"orientation"}))
  {
    $perColumn = 999 ;
    if (@Legend {"orientation"}  =~ /ver/i)
    {
      if (@Legend {"columns"} > 1)
      {
        $perColumn   = 0 ;
        while ((@Legend {"columns"} * $perColumn) < $#LegendData + 1)
        { $perColumn ++ ; }
      }
    }

    for ($l = 1 ; $l <= @Legend {"columns"} ; $l++)
    {
      $script .= "#proc legend\n" ;
      $script .= "  noclear: yes\n" ;
      if (@Legend {"orientation"}  =~ /ver/i)
      { $script .= "  format: multiline\n" ; }
      else
      { $script .= "  format: singleline\n" ; }
      $script .= "  seglen: 0.2\n" ;
      $script .= "  swatchsize: 0.12\n" ;
      $script .= "  textdetails: size=S\n" ;
      $script .= "  location: " . (@Legend{"left"}+0.2) . " " . @Legend{"top"} . "\n" ;
      $script .= "  specifyorder:\n" ;
      for ($l2 = 1 ; $l2 <= $perColumn ; $l2++)
      {
        $category = shift (@LegendData) ;
        if (defined ($category))
        { $script .= "$category\n" ; }
      }
      $script .= "\n" ;
      @Legend {"left"} += @Legend {"columnwidth"} ;
    }
  }

  $script .= "#endproc\n" ;

  print "\nGenerating output:\n" ;
  if ( $plcommand ne "" )
  {
  	$pl = $plcommand;
  } else {
  	$pl = "pl.exe" ;
  	if ($env eq "Linux")
  	{
		$pl = "pl" ;
	}
  }
  print "Using ploticus command \"".$pl."\" (".$plcommand.")\n";

  $script_save = $script ;

  $script =~ s/\(\[inc1\]\)/$scriptSvg1/ ;
  $script =~ s/\(\[inc2\]\)/$scriptSvg2/ ;
  $script =~ s/\(\[inc3\]\)// ;

  $script =~ s/textsize XS/textsize 7/gi ;
  $script =~ s/textsize S/textsize 8.9/gi ;
  $script =~ s/textsize M/textsize 10.5/gi ;
  $script =~ s/textsize L/textsize 13/gi ;
  $script =~ s/textsize XL/textsize 17/gi ;
  $script =~ s/size=XS/size=7/gi ;
  $script =~ s/size=S/size=8.9/gi ;
  $script =~ s/size=M/size=10.5/gi ;
  $script =~ s/size=L/size=13/gi ;
  $script =~ s/size=XL/size=17/gi ;


  $script =~ s/(\n  location:.*)/&ShiftOnePixelForSVG($1)/ge ;

  open "FILE_OUT", ">", $file_script ;
  print FILE_OUT &DecodeInput($script) ;
  close "FILE_OUT" ;

  $map = ($MapSVG) ? "-map" : "";

  print "Running Ploticus to generate svg file\n" ;
  my $cmd = "$pl $map -" . "svg" . " -o $file_vector $file_script -tightcrop" ;
  system ($cmd) ;

  $script = $script_save ;
  $script =~ s/dopagebox: no/dopagebox: yes/ ;

  $script =~ s/\(\[inc1\]\)/$scriptPng1/ ;
  $script =~ s/\(\[inc2\]\)/$scriptPng2/ ;
  $script =~ s/\(\[inc3\]\)/$scriptPng3/ ;

  $script =~ s/textsize XS/textsize 6/gi ;
  $script =~ s/textsize S/textsize 8/gi ;
  $script =~ s/textsize M/textsize 10/gi ;
  $script =~ s/textsize L/textsize 14/gi ;
  $script =~ s/textsize XL/textsize 18/gi ;
  $script =~ s/size=XS/size=6/gi ;
  $script =~ s/size=S/size=8/gi ;
  $script =~ s/size=M/size=10/gi ;
  $script =~ s/size=L/size=14/gi ;
  $script =~ s/size=XL/size=18/gi ;

  open "FILE_OUT", ">", $file_script ;
  print FILE_OUT &DecodeInput($script) ;
  close "FILE_OUT" ;

  $map = ($MapPNG && $linkmap) ? "-csmap" : "";
  if ($linkmap && $showmap)
  { $map .= " -csmapdemo" ; }

# $crop = "-crop 0,0," + @ImageSize {"width"} . "," . @ImageSize {"height"} ;
  print "Running Ploticus to generate bitmap\n" ;
  $cmd = "$pl $map -" . $fmt . " -o $file_bitmap $file_script -tightcrop" ;
  system ($cmd) ;

  if ((-e $file_bitmap) && (-s $file_bitmap > 200 * 1024))
  {
    &Error2 ("Output image size exceeds 200 K. Image deleted.\n" .
             "Run with option -b (bypass checks) when this is correct.\n") ;
    unlink $file_bitmap ;
  } ;

  if ((-e $file_bitmap) && ($fmt eq "gif"))
  {
    print "Running nconvert to convert gif image to png format\n\n" ;
    print "---------------------------------------------------------------------------\n" ;
    $cmd = "nconvert.exe -out png $file_bitmap" ;
    system ($cmd) ;
    print "---------------------------------------------------------------------------\n" ;

    if (! (-e $file_png))
    { print "PNG file not created (is nconvert.exe missing?)\n\n" ; }
  }

  if (-e $file_vector)
  {
    open "FILE_IN", "<", $file_vector ;
    @svg = <FILE_IN> ;
    close "FILE_IN" ;

    foreach $line (@svg)
    {
      $line =~ s/\[(\d+)\[ (.*?) \]\d+\]/'<a style="fill:blue;" xlink:href="' . @linksSVG[$1] . '">' . $2 . '<\/a>'/gxe ;
      $line =~ s/\{\{(\d+)\}\}x+/@textsSVG[$1]/gxe ;
    }

    open "FILE_OUT", ">", $file_vector ;
    print FILE_OUT @svg ;
    close "FILE_OUT" ;
  }

  if ($makehtml)
  {
    $map = "" ;
    if ($linkmap)
    {
      open "FILE_IN", "<", $file_htmlmap ;
      while ($line = <FILE_IN>)
      { $map .= $line ; }
      close "FILE_IN" ;
    }
    print "Generating html test file\n" ;
    $width  = sprintf ("%.0f", @Image {"width"}  * 100) ;
    $height = sprintf ("%.0f", @Image {"height"} * 100) ;
    $html = <<__HTML__ ;

<html>
<head>
<title>Graphical Timelines - HTML test file</title>\n
</head>

<body>
<h1><font color="green">EasyTimeline</font> - Test Page</h1>

<b>Fixed size version (PNG): file $file_png</b><p>
<map name="map1">
$map</map>

<!--
If you want a border simplest way is set <img .. border='1'>
Here tables are used to draw similar borders around both images (border='1' seems not to work for embed tag)
-->

<table border='1' cellpadding='0' cellspacing='0'><tr><td>
<img src=$file_png usemap='#map1' border='0'>
</td></tr></table>

<hr>
<b>Scalable version (SVG): file $file_vector</b><p>
<table border='1' cellpadding='0' cellspacing='0'><tr><td>
<noembed>Your browser does not support embedded objects</noembed>
<embed src='$file_vector' name='SVGEmbed' border='1'
width='$width' height='$height' type='image/svg-xml' pluginspage='http://www.adobe.com/svg/viewer/install/'>
</td></tr></table>

<p>As you can see the scalable version renders fonts smoother better than the bitmap version.
<br>Any SVG picture can also be rescaled or zoomed into, without annoying artefacts.

<p>Windows users:<br>
<small>&nbsp;&nbsp;Right mouse click on picture for zoom options or</small>
<p><small>&nbsp;&nbsp;Ctrl+click for zoom in</small>
<br><small>&nbsp;&nbsp;Ctrl+Shift+click for zoom out</small>
<br><small>&nbsp;&nbsp;Alt+drag with mouse to move focus</small>

</body>
</html>

__HTML__

    open "FILE_OUT", ">", $file_html ;
    print FILE_OUT $html ;
    close "FILE_OUT" ;
  }
#  my $cmd = "\"c:\\\\Program Files\\\\XnView\\\\xnview.exe\"" ;
#  system ("\"c:\\\\Program Files\\\\XnView\\\\xnview.exe\"", "d:\\\\Wikipedia\\Perl\\\\Wo2\\\\Test.png") ;
}

sub PlotBars
{
  #proc getdata / #proc bars
  while ($#PlotBarsNow >= 0)
  {
    undef @PlotBarsNow2 ;

    $maxwidth = 0 ;
    foreach $entry (@PlotBarsNow)
    {
      ($width) = split (",", $entry) ;
      if ($width > $maxwidth)
      { $maxwidth = $width ; }
    }

    $script .= "#proc getdata\n" ;
    $script .= "  delim: comma\n" ;
    $script .= "  data:\n" ;

    foreach $entry (@PlotBarsNow)
    {
      my ($width, @fields) = split (",", $entry) ;
      if ($width < $maxwidth)
      {
        push @PlotBarsNow2, $entry ;
        next ;
      }
      for ($b = 0 ; $b <= $#Bars ; $b++)
      {
        if (lc(@Bars [$b]) eq lc(@fields[0]))
        { @fields[0] = ($#Bars - ($b - 1)) ; last ; }
      }
      $entry = join (",", @fields) ;
      $script .= "$entry" ;
    }
    $script .= "\n" ;

    #proc bars
    $script .= "#proc bars\n" ;
    $script .= "  axis: " . @Axis {"time"} . "\n" ;
    $script .= "  barwidth: $maxwidth\n" ;
    $script .= "  outline: no\n" ;
    if (@Axis {"time"} eq "x")
    { $script .= "  horizontalbars: yes\n" ; }
    $script .= "  locfield: 1\n" ;
    $script .= "  segmentfields: 2 3\n" ;
    $script .= "  colorfield: 4\n" ;
#    if (@fields [4] ne "")
#    { $script .= "  clickmapurl: " . &LinkToUrl ($text) . "\n" ; }
#    if (@fields [5] ne "")
#    { $script .= "  clickmaplabel: $text\n" ; }
    $script .= "  clickmapurl: \@\@5\n" ;
    $script .= "  clickmaplabel: \@\@6\n" ;
    $script .= "\n" ;

    @PlotBarsNow = @PlotBarsNow2 ;
  }
}

sub PlotScale
{
  my $order = shift ;
  my $grid  = shift ;
  my ($color) ;

  $script .= "#proc " . @Axis {"time"} . "axis\n" ;

  if (($order eq "Major") && (! $grid))
  {
    $script .= "  stubs: incremental " . @Scales {"Major inc"} . " " . @Scales {"Major unit"} . "\n" ;
    if ($DateFormat =~ /\//)
    { $script .= "  stubformat: " . @Axis {"format"} . "\n" ; }
  }
  else
  { $script .= "  stubs: none\n" ; }

  $script .= "  ticincrement: " . @Scales {"$order inc"} . " " . @Scales {"$order unit"} . "\n" ;

  if (defined (@Scales {"$order start"}))
  { $script .= "  stubrange: " . @Scales {"$order start"} . "\n" ; }

  if ($order eq "Major")
  { $script .= "  ticlen: 0.05\n" ; }
  else
  { $script .= "  ticlen: 0.02\n" ; }

  $color .= @Scales {"$order grid"} ;

  if (defined (@Colors {$color}))
  { $color = @Colors {$color} ; }

  if ($grid)
  { $script .= "  grid: color=$color\n" ; }

  $script .= "\n" ;
}

sub ColorPredefined
{
  my $color = shift ;
  if ($color =~ /^(?:black|white|tan1|tan2|red|magenta|claret|coral|pink|orange|
                     redorange|lightorange|yellow|yellow2|dullyellow|yelloworange|
                     brightgreen|green|kelleygreen|teal|drabgreen|yellowgreen|
                     limegreen|brightblue|darkblue|blue|oceanblue|skyblue|
                      purple|lavender|lightpurple|powderblue|powderblue2)$/xi)
  {
    if (! defined (@Colors {lc ($color)}))
    { &StoreColor ($color, $color, "", $command) ; }
    return ($True) ;
  }
  else
  { return ($False) ; }
}

sub ValidAbs
{
  $value = shift ;
  if ($value =~ /^ \d+ \.? \d* (?:px|in|cm)? $/xi)
  { return ($True) ; }
  else
  { return ($False) ; }
}

sub ValidAbsRel
{
  $value = shift ;
  if ($value =~ /^ \d+ \.? \d* (?:px|in|cm|$hPerc)? $/xi)
  { return ($True) ; }
  else
  { return ($False) ; }
}

sub ValidDateFormat
{
  my $date = shift ;
  my ($day, $month, $year) ;

  if ($DateFormat eq "yyyy")
  {
    if (! ($date=~ /^\-?\d+$/))
    { return ($False) ; }
    return ($True) ;
  }

  if ($DateFormat eq "x.y")
  {
    if (! ($date=~ /^\-?\d+(?:\.\d+)?$/))
    { return ($False) ; }
    return ($True) ;
  }

  if (! ($date=~ /^\d\d\/\d\d\/\d\d\d\d$/))
  { return ($False) ; }

  if ($DateFormat eq "dd/mm/yyyy")
  {
    $day   = substr ($date,0,2) ;
    $month = substr ($date,3,2) ;
    $year  = substr ($date,6,4) ;
  }
  else
  {
    $day   = substr ($date,3,2) ;
    $month = substr ($date,0,2) ;
    $year  = substr ($date,6,4) ;
  }

  if ($month =~ /01|03|05|07|08|10|12/)
  { if ($day > 31) { return ($False) ; }}
  elsif ($month =~ /04|06|09|11/)
  { if ($day > 30) { return ($False) ; }}
  elsif ($month =~ /02/)
  {
    if (($year % 4 == 0) && ($year % 100 != 0))
    { if ($day > 29) { return ($False) ; }}
    else
    { if ($day > 28) { return ($False) ; }}
  }
  else { return ($False) ; }
  return ($True) ;
}

sub ValidDateRange
{
  my  $date = shift ;
  my ($day,  $month,  $year,
      $dayf, $monthf, $yearf,
      $dayt, $montht, $yeart) ;

  my $from = @Period {"from"} ;
  my $till = @Period {"till"} ;

  if (($DateFormat eq "yyyy") || ($DateFormat eq "x.y"))
  {
    if (($date < $from) || ($date > $till))
    { return ($False) ; }
    return ($True) ;
  }

  if ($DateFormat eq "dd/mm/yyyy")
  {
    $day    = substr ($date,0,2) ;
    $month  = substr ($date,3,2) ;
    $year   = substr ($date,6,4) ;
    $dayf   = substr ($from,0,2) ;
    $monthf = substr ($from,3,2) ;
    $yearf  = substr ($from,6,4) ;
    $dayt   = substr ($till,0,2) ;
    $montht = substr ($till,3,2) ;
    $yeart  = substr ($till,6,4) ;
  }
  if ($DateFormat eq "mm/dd/yyyy")
  {
    $day    = substr ($date,3,2) ;
    $month  = substr ($date,0,2) ;
    $year   = substr ($date,6,4) ;
    $dayf   = substr ($from,3,2) ;
    $monthf = substr ($from,0,2) ;
    $yearf  = substr ($from,6,4) ;
    $dayt   = substr ($till,3,2) ;
    $montht = substr ($till,0,2) ;
    $yeart  = substr ($till,6,4) ;
  }

  if (($year < $yearf) ||
      (($year == $yearf) &&
       (($month < $monthf) ||
        (($month == $monthf) && ($day < $dayf))
       )))
  { return ($False) }

  if (($year > $yeart) ||
      (($year == $yeart) &&
       (($month > $montht) ||
        (($month == $montht) && ($day > $dayt))
       )))
  { return ($False) }

  return ($True) ;
}

sub DateMedium
{
  my $from = shift ;
  my $till = shift ;

  if (($DateFormat eq "yyyy") || ($DateFormat eq "x.y"))
  { return (sprintf ("%.3f", ($from + $till) / 2)) ; }

  $from2 = &DaysFrom1800 ($from) ;
  $till2 = &DaysFrom1800 ($till) ;
  my $date = &DateFrom1800 (int (($from2 + $till2) / 2)) ;
  return ($date) ;
}

sub DaysFrom1800
{
  @mmm = (31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31) ;
  my $date = shift ;
  if ($DateFormat eq "dd/mm/yyyy")
  {
    $day   = substr ($date,0,2) ;
    $month = substr ($date,3,2) ;
    $year  = substr ($date,6,4) ;
  }
  else
  {
    $day   = substr ($date,3,2) ;
    $month = substr ($date,0,2) ;
    $year  = substr ($date,6,4) ;
  }
  if ($year < 1800)
  { &Error2 ("Function 'DaysFrom1800' expects year >= 1800, not '$year'.") ; return ; }

  $days = ($year - 1800) * 365 ;
  $days += int (($year -1 - 1800) / 4) ;
  $days -= int (($year -1 - 1800) / 100) ;
  if ($month > 1)
  {
    for ($m = $month - 2 ; $m >= 0 ; $m--)
    {
      $days += @mmm [$m] ;
      if ($m == 1)
      {
        if ((($year % 4) == 0) && (($year % 100) != 0))
        { $days ++ ; }
      }
    }
  }
  $days += $day ;

  return ($days) ;
}

sub DateFrom1800
{
  my $days = shift ;

  @mmm = (31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31) ;

  $year = 1800 ;
  while ($days > 366)
  {
    if ((($year % 4) == 0) && (($year % 100) != 0))
    { $days -= 366 ; }
    else
    { $days -= 365 ; }
    $year ++ ;
  }

  $month = 0 ;
  while ($days > @mmm [$month])
  {
    $days -= @mmm [$month] ;
    if ($month == 1)
    {
      if ((($year % 4) == 0) && (($year % 100) != 0))
      { $days -- ; } ;
    }
    $month++ ;
  }
  $day = $days ;

  $month ++ ;
  if ($DateFormat eq "dd/mm/yyyy")
  { $date = sprintf ("%02d/%02d/%04d", $day, $month, $year) ; }
  else
  { $date = sprintf ("%02d/%02d/%04d", $month, $day, $year) ; }

  return ($date) ;
}

sub ExtractText
{
  my $data = shift ;
  my $data2 = $data ;
  my $text = "" ;

  # special case: allow embedded spaces when 'text' is last attribute
# $data2 =~ s/\:\:/\@\#\!/g ;
  if ($data2 =~ /text\:[^\:]+$/)
  {
    $text = $data2 ;
    $text =~ s/^.*?text\:// ;
    $text =~ s/^\s(.*?)\s*$/$1/ ;
    $text =~ s/\\n/\n/g ;
    $text =~ s/\"\"/\@\#\$/g ;
    $text =~ s/\"//g ;
    $text =~ s/\@\#\$/"/g ;
    $data2 =~ s/text\:.*$// ;
  }

  # extract text between double quotes
  $data2 =~ s/\"\"/\@\#\$/g ;
  if ($data2 =~ /text\:\"/)
  {
    $text = $data2 ;
    $text =~ s/^.*?text\:\"// ;

    if (! ($text =~ /\"/))
    { &Error ("PlotData invalid. Attribute 'text': no closing \" found.") ;
      &GetData ; next PlotData ; }

    $text =~ s/\".*$//;
    $text =~ s/\@\#\$/"/g ;
    $text =~ s/\\n/\n/g ;
  }
  $data2 =~ s/text\:\"[^\"]*\"// ;
  $data2 =~ s/\@\#\$/"/g ;
  return ($data2, $text) ;
}

sub ParseText
{
  my $text = shift ;
  $text =~ s/\_\_/\@\#\$/g ;
  $text =~ s/\_/ /g ;
  $text =~ s/\@\#\$/_/g ;

  $text =~ s/\~\~/\@\#\$/g ;
  $text =~ s/\~/\\n/g ;
  $text =~ s/\@\#\$/~/g ;

  return ($text) ;
}

sub BarDefined
{
  my $bar = shift ;
  foreach $bar2 (@Bars)
  {
    if (lc ($bar2) eq lc ($bar))
    { return ($True) ; }
  }
  return ($False) ;
}

sub ValidAttributes
{
  my $command = shift ;

  if ($command =~ /^BackgroundColors$/i)
  { return (CheckAttributes ($command, "", "canvas,bars")) ; }

  if ($command =~ /^BarData$/i)
  { return (CheckAttributes ($command, "bar", "link,text")) ; }

  if ($command =~ /^Colors$/i)
  { return (CheckAttributes ($command, "id,value", "legend")) ; }

  if ($command =~ /^DrawLines$/i)
  { return (CheckAttributes ($command, "at,color", "")) ; }

  if ($command =~ /^ImageSize$/i)
  { return (CheckAttributes ($command, "width,height", "")) ; }

  if ($command =~ /^Legend$/i)
  { return (CheckAttributes ($command, "", "columns,columnwidth,orientation,position,left,top")) ; }

  if ($command =~ /^Period$/i)
  { return (CheckAttributes ($command, "from,till", "")) ; }

  if ($command =~ /^PlotArea$/i)
  { return (CheckAttributes ($command, "width,height,left,bottom", "")) ; }

  if ($command =~ /^PlotData$/i)
  { return (CheckAttributes ($command, "", "align,at,bar,color,fontsize,from,link,mark,shift,text,textcolor,till,width")) ; }

  if ($command =~ /^Scale$/i)
  { return (CheckAttributes ($command, "unit,increment,start", "grid")) ; }

  if ($command =~ /^TextData$/i)
  { return (CheckAttributes ($command, "", "fontsize,lineheight,link,pos,tabs,text,textcolor")) ; }

  if ($command =~ /^TimeAxis$/i)
  { return (CheckAttributes ($command, "", "orientation,format")) ; }

  return ($True) ;
}

sub CheckAttributes
{
  my $name     = shift ;
  my @Required = split (",", shift) ;
  my @Allowed  = split (",", shift) ;

  my $attribute ;
  my %Attributes2 = %Attributes ;

  $hint = "\nSyntax: '$name =" ;
  foreach $attribute (@Required)
  { $hint .= " $attribute:.." ; }
  foreach $attribute (@Allowed)
  { $hint .= " [$attribute:..]" ; }
  $hint .= "'" ;

  foreach $attribute (@Required)
  {
    if ((! defined (@Attributes {$attribute})) || (@Attributes {$attribute} eq ""))
    { &Error ("$name definition incomplete. $hint") ;
      undef (@Attributes) ; return ($False) ; }
    delete (@Attributes2 {$attribute}) ;
  }
  foreach $attribute (@Allowed)
  { delete (@Attributes2 {$attribute}) ; }

  @AttrKeys = keys %Attributes2 ;
  if ($#AttrKeys >= 0)
  {
    if (@AttrKeys [0] eq "single")
    { &Error ("$name definition invalid. Specify all attributes as name:value pairs.") ; }
    else
    { &Error ("$name definition invalid. Invalid attribute '" . @AttrKeys [0] . "' found. $hint") ; }
    undef (@Attributes) ; return ($False) ; }

  return ($True) ;
}

sub ShiftOnePixelForSVG
{
  my $line = shift ;
  $line =~ s/location:\s*// ;
  my ($posx, $posy) = split (" ", $line) ;

  if ($posy =~ /\+/)
  { ($posy1, $posy2) = split ('\+', $posy) ; }
  elsif ($posy =~ /\-/)
  { ($posy1, $posy2) = split ('\-', $posy) ; $posy2 = - $posy2 }
  else
  { $posy1 = $posy ; $posy2 = 0 ; }

  if ($posy1 !~ /(s)/)
  { $posy += 0.01 ; }
  else
  {
    $posy2 += 0.01 ;
    if ($posy2 == 0)
    { $posy = $posy1 ; }
    elsif ($posy2 < 0)
    { $posy = $posy1 . "$posy2" ; }
    else
    { $posy = $posy1 . "+" . $posy2 ; }
  }

  $line = "\n  location: $posx $posy" ;
  return ($line) ;
}

sub NormalizeURL
{
  my $url = shift ;
  $url =~ s/(https?)\:?\/?\/?/$1:\/\// ; # add possibly missing special characters
  $url =~ s/ /%20/g ;
  return ($url) ;
}

# wiki style link may include linebreak characters -> split into several wiki links
sub NormalizeWikiLink
{
  my $text = shift ;
  $text =~ s/\[\[// ;
  $text =~ s/\]\]// ;
  my ($hide,$show) = split ('\|', $text) ;
  my @Show = split ("\n", $show) ;
  $text = "" ;
  foreach $part (@Show)
  { $part = "[[" . $hide . "|" . $part . "]]" } ;
  $text = join ("\n", @Show) ;
  return ($text) ;
}

sub ProcessWikiLink
{
  my $text = shift ;
  my $link = shift ;
  my $hint = shift ;
  my $wikilink = $False ;

  chomp ($text) ;
  chomp ($link) ;
  chomp ($hint) ;

  if ($text =~ /moscow/i)
  { $a = 1 ; }

  my ($wiki, $title) ;
  if ($link ne "") # ignore wiki brackets in text when explicit link is specified
  {
    $text =~ s/\[\[ [^\|]+ \| (.*) \]\]/$1/gx ;
    $text =~ s/\[\[ [^\:]+ \: (.*) \]\]/$1/gx ;
#   $text =~ s/\[\[ (.*) \]\]/$1/gx ;
  }
  else
  {
    if ($text =~ /\[.+\]/) # keep first link in text segment, remove others
    {
      $link = $text ;
      $link =~ s/\n//g ;
      $link =~ s/^[^\[\]]*\[/[/x ;

      if ($link =~ /^\[\[/)
      { $wikilink = $True ; }

      $link =~ s/^ [^\[]* \[+ ([^\[\]]*) \].*$/$1/x ;
      $link =~ s/\|.*$// ;
      if ($wikilink)
      { $link = "[[" . $link . "]]" ; }

      $text =~ s/(\[+) [^\|\]]+ \| ([^\]]*) (\]+)/$1$2$3/gx ;
      $text =~ s/(https?)\:/$1colon/gx ;
      $text =~ s/(\[+) [^\:\]]+ \: ([^\]]*) (\]+)/$1$2$3/gx ;

      $text =~ s/\[+ ([^\]]+) \]+/{{{$1}}}/x ;
      $text =~ s/\[+ ([^\]]+) \]+/$1/gx ;
      $text =~ s/\{\{\{ ([^\}]*) \}\}\}/[[$1]]/x ;
    }
#    if ($text =~ /\[\[.+\]\]/)
#    {
#      $wikilink = $True ;
#      $link = $text ;
#      $link =~ s/\n//g ;
#      $link =~ s/^.*?\[\[/[[/x ;
#      $link =~ s/\| .*? \]\].*$/]]/x ;
#      $link =~ s/\]\].*$/]]/x ;
#      $text =~ s/\[\[ [^\|\]]+ \| (.*?) \]\]/[[$1]]/x ;
#      $text =~ s/\[\[ [^\:\]]+ \: (.*?) \]\]/[[$1]]/x ;

#      # remove remaining links
#      $text =~ s/\[\[ ([^\]]+) \]\]/^%#$1#%^/x ;
#      $text =~ s/\[+ ([^\]]+) \]+/$1/gx ;
#      $text =~ s/\^$hPerc\# (.*?) \#$hPerc\^/[[$1]]/x ;
#    }
#    elsif ($text =~ /\[.+\]/)
#    {
#      $link = $text ;
#      $link =~ s/\n//g ;
#      $link =~ s/^.*?\[/[/x ;
#      $link =~ s/\| .*? \].*$/]/x ;
#      $link =~ s/\].*$/]/x ;
#      $link =~ s/\[ ([^\]]+) \]/$1/x ;
#      $text =~ s/\[ [^\|\]]+ \| (.*?) \]/[[$1]]/x ;

#      # remove remaining links
#      $text =~ s/\[\[ ([^\]]+) \]\]/^%#$1#%^/x ;
#      $text =~ s/\[+ ([^\]]+) \]+/$1/gx ;
#      $text =~ s/\^$hPerc\# (.*?) \#$hPerc\^/[[$1]]/x ;
##     $text =~ s/\[\[ (.*) \]\]/$1/gx ;
#    }

  }

  if ($wikilink)
  {
    if ($link =~ /^\[\[.+\:.+\]\]$/) # Has a colon in its name
    {
      $wiki  = lc ($link) ;
      $title = $link ;
      $wiki  =~ s/\[\[([^\:]+)\:.*$/$1/x ;
      $title =~ s/^[^\:]+\:(.*)\]\]$/$1/x ;
      if ($wiki eq "www")
      { $wiki = "en" ; }
    }
    else
    {
      $wiki = "en" ;
      $title = $link ;
      $title =~ s/^\[\[(.*)\]\]$/$1/x ;
    }
    $title =~ s/ /_/g ;
    $link = $articlepath . "/$title" ;
  }

  if (($hint eq "") && ($title ne ""))
  { $hint = "$title" ; }

  if (($link ne "") && ($text !~ /\[\[/) && ($text !~ /\]\]/))
  { $text = "[[" . $text . "]]" ; }

  $hint = &EncodeHtml ($hint) ;
  $link = &EncodeURL  ($link) ;
  return ($text, $link, $hint) ;
}

sub EncodeInput
{
  my $text = shift ;
  $text =~ s/([\`\{\}\%\&\@\$\(\)\;\=])/"<" . sprintf ("%X", ord($1)) . ">";/ge ;
  return ($text) ;
}

sub DecodeInput
{
  my $text = shift ;
  $text =~ s/<([0-9A-F]{2})>/chr(hex($1))/ge ;
  return ($text) ;
}

sub EncodeHtml
{
  my $text = shift ;
  $text =~ s/([\<\>\&\'\"])/"\&\#" . ord($1) . "\;"/ge ;
  $text =~ s/\n/<br>/g ;
  return ($text) ;
}

sub EncodeURL
{
  my $url = shift ;
  $url =~ s/([^0-9a-zA-Z\%\:\/\.])/"%".sprintf ("%X",ord($1))/ge ;
  return ($url) ;
}

sub Error
{
  my $msg = &DecodeInput(shift) ;
  $msg =~ s/\n\s*/\n  /g ; # indent consecutive lines

  $CntErrors++ ;
  if (! $listinput)
  { push @Errors, "Line $LineNo: " . &DecodeInput($Line) . "\n" ; }
  push @Errors, "- $msg\n\n" ;
  if ($CntErrors > 10)
  { &Abort ("More than 10 errors found") ; }
}

sub Error2
{
  my $msg = &DecodeInput(shift) ;
  $msg =~ s/\n\s*/\n  /g ; # indent consecutive lines
  $CntErrors++ ;
  push @Errors, "- $msg\n" ;
}

sub Warning
{
  my $msg = &DecodeInput(shift) ;
  $msg =~ s/\n\s*/\n  /g ; # indent consecutive lines
  if (! $listinput)
  { push @Warnings, "Line $LineNo: " . &DecodeInput ($Line) . "\n" ; }
  push @Warnings, "- $msg\n\n" ;
}

sub Warning2
{
  my $msg = &DecodeInput(shift) ;
  $msg =~ s/\n\s*/\n  /g ; # indent consecutive lines
  push @Warnings, "- $msg\n" ;
}

sub Info
{
  my $msg = &DecodeInput(shift) ;
  $msg =~ s/\n\s*/\n  /g ; # indent consecutive lines
  if (! $listinput)
  { push @Info, "Line $LineNo: " . &DecodeInput ($Line) . "\n" ; }
  push @Info, "- $msg\n\n" ;
}

sub Info2
{
  my $msg = &DecodeInput(shift) ;
  $msg =~ s/\n\s*/\n  /g ; # indent consecutive lines
  push @Info, "- $msg\n" ;
}

sub Abort
{
  my $msg = &DecodeInput(shift) ;

  print "\n\n***** " . $msg . " *****\n\n" ;
  print @Errors ;
  print "EasyTimeline execution aborted.\n" ;

  open "FILE_OUT", ">", $file_errors ;
  print FILE_OUT "<p><b>Timeline generation failed: " . &EncodeHtml ($msg) ."</b><p>\n" ;
  foreach $line (@Errors)
  { print FILE_OUT &EncodeHtml ($line) . "\n" ; }
  close "FILE_OUT" ;

  if ($makehtml) # generate html test file, which would normally contain png + svg (+ image map)
  {
    open "FILE_IN",  "<", $file_errors ;
    open "FILE_OUT", ">", $file_html ;
    print FILE_OUT "<html><head>\n<title>Graphical Timelines - HTML test file</title>\n</head>\n" .
                   "<body><h1><font color='green'>EasyTimeline</font> - Test Page</h1>\n\n" .
                   "<code>\n" ;
    print FILE_OUT <FILE_IN> ;
    print FILE_OUT "</code>\n\n</body>\n</html>" ;
    close "FILE_IN" ;
    close "FILE_OUT" ;
  }
  exit ;
}


