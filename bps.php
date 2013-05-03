#!/usr/bin/php
<?php
/**
 * bps.php - create image "breakpoints" based on filesize, not dimensions
 * 
 * Jason Grigsby had an idea: what if we calculate image breakpoints based on an allowable
 * filesize increase and let the resultant width and height dimensions follow from that?
 * Basically, another way to look at image breakpoint decision making.  WTH?
 * 
 * Here's what we do: given a large source image, the dimensions of an optimal "lower"
 * image in context, the dimensions of an allowable "upper" image in context, and a step
 * function in filesize, create images at each size point between the lower and upper
 * bound.
 *
 * Files are created in the current working directory and named like:
 * Example: filename-WxH.extension
 */

$warn = TRUE;
$info = FALSE;

//////////////////////////////////////////////////////////////////////
// Utility functions
//////////////////////////////////////////////////////////////////////

function info($message) {
  global $info;
  if ($info) {
    print 'Info: ' . $message . "\n";
  }
}

function warn($message) {
  global $warn;
  if ($warn) {
    print 'Warning: ' . $message . "\n";
  }
}

function error($message) {
  print 'Error: ' . $message . "\n";
  print "Usage: bps.php [ -v | --verbose ] [ -q | --quiet ] --source=filename> --step=bytes --lower=WxH [ --upper=WxH ]\n";
  exit(1);
}

///////////////////////////////////////////////////////////////////////
// Main
///////////////////////////////////////////////////////////////////////

$vars = array();
$vars['lower'] = FALSE;
$vars['upper'] = FALSE;
$vars['step']  = FALSE;
$vars['source']  = FALSE;

$shortopts = 'vq';
$longopts = array('lower:', 'upper::', 'step:', 'source:', 'verbose', 'quiet');
$opts = getopt($shortopts, $longopts);

// Get arguments
foreach ($opts as $key => $value) {
  $vars[$key] = $value;
}

// Check arguments
foreach ($vars as $key => $value) {
  switch ($key) {
  case 'lower':
  case 'step':
  case 'source':
    if ($value === FALSE) {
      error('Missing arguments');
    }
    break;
  case 'v':
  case 'verbose':
    $info = TRUE;
    break;
  case 'q':
  case 'quiet':
    $info = FALSE;
    $warn = FALSE;
    break;
  }
}

// Info about the source image
$source_parts = pathinfo($vars['source']);
$source_filename = $source_parts['filename'];
$source_extension = $source_parts['extension'];

$source_filesize = filesize($vars['source']);
$source_size = getimagesize($vars['source']);
$source_width = $source_size[0];
$source_height = $source_size[1];

// Set upper value if not already set
if ($vars['upper'] === FALSE) {
  $vars['upper'] = $source_width . 'x' . $source_height;
}

// Limit the upper value to the size of the source image
list($upper_width, $upper_height) = split("x", $vars['upper']);
if ($upper_width > $source_width || $upper_height > $source_height) {
  $upper_width = $source_width;
  $upper_height = $source_height;
  $vars['upper'] = $source_width . 'x' . $source_height;
  warn('upper size limit restricted to the source image size of ' . $vars['upper']);
}

// Limit the lower value to the size of the source image, at most
list($lower_width, $lower_height) = split("x", $vars['lower']);
if ($lower_width > $upper_width || $lower_height > $upper_height) {
  $lower_width = $upper_width;
  $lower_height = $upper_height;
  $vars['lower'] = $upper_width . 'x' . $upper_height;
  warn('lower size limit restricted to the upper image size of ' . $vars['lower']);
}

// Create the desired (lower) image dimensions
$temp = $source_filename . '-temp.' . $source_extension;
exec(escapeshellcmd(implode(' ', array('/usr/bin/convert', '-resize', $vars['lower'], $vars['source'], $temp))));
$lower_filesize = filesize($temp);
$lower_size = getimagesize($temp);
$lower_width = $lower_size[0];
$lower_height = $lower_size[1];

$destination = $source_filename . '-' . $lower_width . 'x' . $lower_height . '.' . $source_extension;
rename ($temp, $destination);
if ($warn || $info) {
  print(implode(' ', array('created', $destination, 'with size', $lower_filesize, 'bytes', "\n")));
}

// Based on the initial image conversion, guess the number of bytes per pixel change
$width_factor = floor($vars['step'] * ($source_width - $lower_width) / ($source_filesize - $lower_filesize));
$height_factor = floor($vars['step'] * ($source_height - $lower_height) / ($source_filesize - $lower_filesize));

// Create the breakpoints based approximately on image filesize
$width = $lower_width;
$height = $lower_height;
$filesize = $lower_filesize;

while (($width < $upper_width) && ($height < $upper_height)) {
  $width += $width_factor;
  $height += $height_factor;
  $filesize += $vars['step'];
  $dimensions = $width . 'x' . $height;
  $seen = array();

  // Create the resized image
  do {

    // Create a test image
    clearstatcache();
    $temp = $source_filename . '-temp.' . $source_extension;
    exec(escapeshellcmd(implode(' ', array('/usr/bin/convert', '-resize', $dimensions, $vars['source'], $temp))));
    $temp_size = getimagesize($temp);
    $temp_width = $temp_size[0];
    $temp_height = $temp_size[1];
    $temp_filesize = filesize($temp);
    $filesize_delta = abs($temp_filesize - $filesize);

    // Are we close enough?
    if ($filesize_delta > 1024) {

      // Have we been here before (non-convergence)
      if (empty($seen[$filesize_delta])) {
	$adjustment = $temp_filesize / $filesize;
	info('calibrating... ' . $adjustment);

	// Bad guess, try again
	$width -= $width_factor;
	$height -= $height_factor;
	$width_factor = floor($width_factor / $adjustment);
	$height_factor = floor($height_factor / $adjustment);
	$width += $width_factor;
	$height += $height_factor;
	$dimensions = $width . 'x' . $height;
	$seen[$filesize_delta] = TRUE;
      }
      // Seen and "reasonably close"
      elseif ($filesize_delta < $vars['step']) {
	info('punting with delta of ' . $filesize_delta);
	break;
      }
    }
  } while ($filesize_delta > 1024);

  // This is our new starting point
  $width = $temp_width;
  $height = $temp_height;

  // Save this temp file as a result
  $destination = $source_filename . '-' . $temp_width . 'x' . $temp_height . '.' . $source_extension;
  rename ($temp, $destination);
  if ($warn || $info) {
    print(implode(' ', array('created', $destination, 'with size', $temp_filesize, 'bytes', "\n")));
  }
}
