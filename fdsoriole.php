#!/usr/bin/php -q
<?php
//
// HVC FDS Oriole, revision 11
// Douglas Winslow <winslowdoug@gmail.com>
// This software reads the FDS disk format for data recovery and repair.
//
// Code versions are on GitHub:   https://github.com/drwiii/FDSoriole/
// Help me via PayPal:            https://www.paypal.me/drwinslow
//

// REVISION LOG
//
// Revision 1:  27-Sep-2020 03:57
//  Find disk header and load directory sequence.
// Revision 2:  27-Sep-2020 04:42
//  Add capability to extract disk files to local disk.
// Revision 3:  27-Sep-2020 06:43
//  Verification of checksum VRAM.
//  Present checksum VRAM if verified and known proper status is found.
// Revision 4:  29-Sep-2020 00:49
//  Normalize hexadecimal display in fileid.
//  Add more VRAM verification criteria.
// Revision 5:  29-Sep-2020 13:36
//  File type index.
//  Add source code comments.
// Revision 6:  29-Sep-2020 14:35
//  Add check() function for hex dump.
// Revision 7:  29-Sep-2020 16:03
//  Update read pointer with post-increment, not pre-increment.
//  New options for check() with a tag option to call out a byte in the dump.
//  Option to denote found disk system format header during initial scan.
// Revision 8:  29-Sep-2020 16:21
//  Remove old notation comments.
//  Add these comments.
//  Disk variation handler: increment 2 bytes before next block ID.
// Revision 9:  30-Sep-2020 16:00
//  Perform sanitization of path names and file names destined for local disk.
//  Add function to make hexadecimal look better.
// Revision 10: 03-Mar-2023 01:23
//  Redo directory printout.
//  Change output file naming.
//  Add option parsing to command line.
// Revision 11: 20-Oct-2023 08:17
//  Change catalog selection routine due to an interpreter update.
//  Accommodate more different filenames.
//  Accommodate out-of-range timestamps.
//
// TO DO:
//  Disk writing input/output functions
//  File system check function
//


// SOFTWARE LICENSE
//
/*
Copyright (c) 2020-2023 DOUGLAS RICE WINSLOW III. All rights reserved.

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions are met:

1. Redistributions of source code must retain the above copyright notice,
   this list of conditions and the following disclaimer.

2. Redistributions in binary form must reproduce the above copyright
   notice, this list of conditions and the following disclaimer in the
   documentation and/or other materials provided with the distribution.

3. Neither the name of the copyright holder nor the names of its
   contributors may be used to endorse or promote products derived from this
   software without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE
LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
POSSIBILITY OF SUCH DAMAGE.
*/
//


// INSTRUCTIONS
//
// OPERATION OF THIS SOFTWARE
//  this code will scan a provided filename for HVC disk system data.
//  the format is found on proprietary QuickDisk media and other systems.
//
// MODIFICATION
//  the default operation is to only list. command line options extract the
//  disk's files onto your local disk, or you can pass the contents of the
//  file into the check() function for review.
//
// OUTPUT FILE NAME FORMAT
//  . (CWD) / system-swmaker-titleid / side-filenum1-filenum2-filename
//


// CHECK()
//  it's a hexadecimal dumper, similar to hexdump -C, but for people who left
//  their expensive compilers at home.
//
function check($data, $offset, $length, $cite=-1)
{
	$bytes = 0;
	$display = "";
	print "\n* check @ byte ".$offset." length ".$length." cite ".$cite."\n{\n        ";
	for ($i=0; $i<=(strlen($data)<16?strlen($data)-1:15); $i++) print " ".dechex($i)." ";
	if ($cite >= 0) print "  ".str_repeat(" ", $cite%16)."V";
	for ($i=0; $i<$length; $i++)
	{
		$byte = $offset + $i;
		if ($bytes%16 == 0)
		{
			print ($i ? " ".$display.( ($bytes-$cite >= 1 and $bytes-$cite <= 16) ?"<":""):"");
//			print "  ".($bytes-$cite);
			print "\n ".bend(dechex($byte),4).": ";
			$display = "";
		}
		$bytes++;
		if (isset($data[$byte]))
		{
			$a = bend(dechex(ord($data[$byte])));
			$display .= ( (ord($data[$byte]) >= 32 and ord($data[$byte]) <= 126) ? chr(hexdec($a)) : "`");
			print ($cite == $i?"'":" ").$a;
		}
		if ($bytes%8 == 0) print " ";
	}
	print " ".$display;
//	print "  ".($bytes-$cite);
	print "\n}\n";
}


// BEND()
//  bend hex output into an agreeable and harumph-compatible output.
//
function bend($input, $x=2, $fill="0")
{
	return(substr(str_repeat($fill, 8).strtoupper($input), -$x));
}


// MAIN
//
if ($argc == 1)		// need an input file name
{
	print "Usage: ".$argv[0]." [-show] [-write] [-verbose] [-crc] [filename]\n";
	exit(1);
}

foreach ($argv as $arg)
{
	if (substr($arg,0,2) == "-s") $_showdata = TRUE;		// option: show file data
	else if (substr($arg,0,2) == "-w") $_writefiles = TRUE;		// option: write files to local disk
	else if (substr($arg,0,2) == "-v") $_verbose = TRUE;		// option: print column headers
	else if (substr($arg,0,2) == "-c") $_getcrc = TRUE;		// option: accommodate CRC variation
	else $localfile = $arg;						// local filename
}

if (file_exists($localfile)) $DF = file_get_contents($localfile);	// load local disk file
if (!isset($DF))
{
	print $argv[0].": couldn't open \"".$localfile."\".\n";
	exit(1);
}

print "\n";
print $localfile."\n";

$s = 0;	// set side counter
$r = 0;	// set initial file read pointer

// 00: Waiting for file system


// SCAN FOR INSTANCES OF HVC FDS DISK FORMAT
//
while ($r < strlen($DF))
{
	// 01: File system follows
	if (substr($DF,$r,2) == chr(1)."*" and crc32(substr($DF,$r+2,8)) == 3571442638 and substr($DF,$r+10,5) == "-HVC*")
	{
		$filemap[$s] = $r;	// note the byte offset of disk header found in file
		$s++;			// increment found side count
	}

	$r++;
}

if (!$s) exit(0);

$s = 0;


// LOAD HVC FDS DISK
//
foreach ($filemap as $offset)	// for each starting offset found in the file map..
{
	$r = $offset;	// set current file read pointer to the start of the disk

//	print str_repeat("-", 79)."\n";
//	print "disk starting @ byte ".$r."\n";
	print "\n";

	$used = 0;
	$unused = 0;
	$filesys = "";

	for($i=14;$i>=1;$i--)$filesys=$DF[$r+$i].$filesys;
//	print_r("  ".$filesys."\n");

	$r += 15;	// seek ahead 15 bytes

	$swmaker = $DF[$r++];
	print "  ".bend(dechex(ord($swmaker)))." ";	// swmaker

	$titleid = $DF[$r++];	// load pointer's byte into variable and then increment.
	$titleid .= $DF[$r++];	// append next byte into the variable and then increment.
	$titleid .= $DF[$r++];	// append next byte into the variable and then increment.
	$titleid .= $DF[$r++];	// append next byte into the variable and then increment.
	print "\"".$titleid."\"\t";	// titleid

	$version = $DF[$r++];
	print "v.".ord($version)." ";	// version

	$diskside = $DF[$r++];
	print "S".ord($diskside)." ";	// diskside

	$disk1nr = $DF[$r++];
	$disk2nr = $DF[$r++];
	$disk3nr = $DF[$r++];

	$useipl = $DF[$r++];
	print bend(dechex(ord($useipl)))."  ";	// useipl

	$r += 5;

	$y1 = $DF[$r++];
	$m1 = $DF[$r++];
	$d1 = $DF[$r++];
	print dechex(ord($m1))."/".dechex(ord($d1))."/".(25+(int)dechex(ord($y1)))." ";		// completed

	$r += 10;

	$y2 = $DF[$r++];
	$m2 = $DF[$r++];
	$d2 = $DF[$r++];
	print dechex(ord($m2))."/".dechex(ord($d2))."/".(25+(int)dechex(ord($y2)))."  ";	// created

	$r += 9;

	if (isset($_getcrc)) $r += 2;	// CRC variation
	$blockid = $DF[$r++];

	// 02: File system directory follows
	if (ord($blockid) == 2)
	{
		$filecount = ord($DF[$r++]);
		print $filecount." files\n";	// filecount
		$countfiles = 0;
		print "\n";
		if (isset($_verbose)) print " n id\t name\t\tload\tsize\tspan\ttype\n";
		while (TRUE)
		{
			if (isset($_getcrc)) $r += 2;	// CRC variation
			$blockid = $DF[$r++];

			// 03: File header follows
			if (ord($blockid) == 3)
			{
				print " ";

				$filenum1 = $DF[$r++];
				$filenum2 = $DF[$r++];

				print ord($filenum1).",";			// filenum
				print bend(dechex(ord($filenum2))).",\t";	// filenum

				$filename = "";
				while (TRUE)
				{
					$filename .= $DF[$r++];
					if (strlen($filename) >= 8) break;
				}
				$filename = trim($filename);
				print "\"".$filename."\"";			// filename
				print str_repeat(" ", 8-strlen($filename))."\t";

				$loadaddr2 = $DF[$r++];
				$loadaddr1 = $DF[$r++];
				$loadaddr = (ord($loadaddr1) * 256) + ord($loadaddr2);
				print "$".bend(dechex($loadaddr),4)."\t";	// loadaddr

				$filesize2 = $DF[$r++];
				$filesize1 = $DF[$r++];
				$filesize = (ord($filesize1) * 256) + ord($filesize2);
				print $filesize."\t";				// filesize

				$loadspan = $loadaddr + $filesize - 1;
				print "$".bend(dechex($loadspan),4)."\t";	// loadspan

				$filetype = $DF[$r++];
				print ord($filetype)." ";			// filetype
				if (ord($filetype) == 0) print "PRG";
				else if (ord($filetype) == 1) print "CHR";
				else if (ord($filetype) == 2) print "VRAM";
				else print "?";

				if (isset($_getcrc)) $r += 2;	// CRC variation
				$blockid = $DF[$r++];

				// 04: File data follows
				if (ord($blockid) == 4)
				{
					$data = substr($DF, $r, $filesize);
					if (isset($_showdata)) check($data, 0, strlen($data));
					$r += $filesize;
					$used += $filesize;
					if ( TRUE != FALSE
						and ord($filenum1) == 0
						and strlen($data) == 224
						and crc32($data) == 798990613
						and $data[0] == "$" )
					{
						// catalog header found
						print " \\";
						$RLZ = "";
						for ($y=0; $y<strlen($data); $y++)
							$RLZ .= chr(ord($data[$y])+55).($y%32==31?"\n":"");
					}

					if (isset($_writefiles) and $_writefiles == TRUE)
					{
						$putdir = str_replace("/", "", substr($DF,$offset+11,3)."-".bend(dechex(ord($swmaker)))."-".chop($titleid));
						$putfile = str_replace("/", "", $s."-".ord($filenum1)."-".bend(dechex(ord($filenum2)))."-".$filename);
						if (!file_exists("./".$putdir."/")) mkdir("./".$putdir."/");	// make directory if absent
						file_put_contents("./".$putdir."/".$putfile, $data);		// overwrite file in directory
					}
				}

				$countfiles++;
				print "\n";
			}
			else
				break;
		}

		if ($filecount != $countfiles) print "\n** File count wrong. Check file system. **\n";
	}

//	print "\n".$used." bytes filed.\n";
	$s++;
}

print "\n";

//
// END OF FILE (EOF) FOLLOWS
?>
