#!/usr/bin/php -q
<?php
//
// HVC FDS Oriole, revision 9
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
//
// TO DO:
//  Restrict output file names to use printable characters only
//  (use tab completion: it's convenient and someone set you up to look cool)
//


// SOFTWARE LICENSE
//
/*

Copyright (c) 2020 DOUGLAS RICE WINSLOW III. All rights reserved.

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
//  there are some lines commented out. the default operation is to only list.
//  by uncommenting some of the code below, you can make it extract the disk's
//  image files onto your local disk, or you can pass the contents of the file
//  into the check() function for review.
//
// OUTPUT FILE NAME FORMAT
//  . (CWD) / system-titleid-diskside / filenum1-filenum2-filename
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
			$a = bend(dechex(ord($data[$byte])),2);
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
function bend($input, $x=2)
{
	return(substr("00000000".strtoupper($input), -$x));
}


// MAIN
//
print "HVC FDS Oriole, rv.9 [drw 30-Sep-2020]\n";

if ($argc == 1) exit(1);	// need an input file name
if (!$argv[1]) exit(1);

$_hvcfiletype = array("PRG", "CHR", "VRAM");

print "\n";
print "file: ".$argv[1]."\n";
print "size: ".filesize($argv[1])."\n";
print "\n";

$DF = file_get_contents($argv[1]);	// load local disk file
if (!$DF) exit(1);

$s = 0;
$r = 0;	// set initial file read pointer


// SCAN FOR INSTANCES OF HVC FDS DISK FORMAT
//
while ($r < strlen($DF))
{
	if (substr($DF,$r,2) == chr(1)."*" and crc32(substr($DF,$r+2,8)) == 3571442638 and substr($DF,$r+10,5) == "-HVC*")	// 01 = File system follows
	{
//		print "Found ".substr($DF,$r+1,14)."\n";
		$filemap[$s] = $r;	// note byte offset of disk header found in file
		$s++;			// increment found side count
	}

	$r++;
}

if (!$s) exit(0);


// LOAD HVC FDS DISK
//
foreach ($filemap as $offset)	// for each starting offset found in the file map..
{
	$r = $offset;	// set current file read pointer to the start of the disk
	print str_repeat("-", 39)."\n";
	print "disk starting @ byte ".$r."\n\n";

	$r += 15;	// seek ahead 15 bytes

	$swmaker = $DF[$r++];
	print "swmaker: ".bend(dechex(ord($swmaker)),2)."\n";

	$titleid = $DF[$r++];	// load pointer's byte into variable and increment.
	$titleid .= $DF[$r++];	// append next byte into the variable and increment.
	$titleid .= $DF[$r++];	// append next byte into the variable and increment.
	$titleid .= $DF[$r++];	// append next byte into the variable and increment.
	print "titleid: \"".$titleid."\"\n";

	$version = $DF[$r++];
	print "version: ".ord($version)."\n";

	$diskside = $DF[$r++];
	print "diskside: ".ord($diskside)."\n";

	$disk1nr = $DF[$r++];
	$disk2nr = $DF[$r++];
	$disk3nr = $DF[$r++];

	$useipl = $DF[$r++];
//	print "useipl: ".bend(dechex(ord($useipl)),2)."\n";

	$r += 5;

	$y1 = $DF[$r++];
	$m1 = $DF[$r++];
	$d1 = $DF[$r++];
	print "completed: ";
	print dechex(ord($m1))."/".dechex(ord($d1))."/";
	print (1925+dechex(ord($y1)))." ";
	print "\n";

	$r += 10;

	$y2 = $DF[$r++];
	$m2 = $DF[$r++];
	$d2 = $DF[$r++];
	print "created: ";
	print dechex(ord($m2))."/".dechex(ord($d2))."/";
	print (1925+dechex(ord($y2)))." ";
	print "\n";

	$r += 9;

//	check($DF,$r,18); $r++; $r++;	// CRC variation
	$blockid = $DF[$r++];
	if (ord($blockid) == 2)	// 02 = File system directory follows
	{
		$filecount = ord($DF[$r++]);
		print "filecount: ".$filecount."\n";
		$countfiles = 0;

		while (TRUE)
		{
//			check($DF,$r,18); $r++; $r++;	// CRC variation
			$blockid = $DF[$r++];
			if (ord($blockid) == 3)	// 03 = File header follows
			{
				print "\n";

				$filenum1 = $DF[$r++];
				$filenum2 = $DF[$r++];

				print "fileid: ";
				print bend(dechex(ord($filenum1)),2).", ";
				print bend(dechex(ord($filenum2)),2)."\n";

				$filename = "";
				while (TRUE)
				{
					$filename .= $DF[$r++];
					if (strlen($filename) >= 8) break;
				}
				$filename = trim($filename);
				print "filename: \"".$filename."\"\n";

				$loadaddr2 = $DF[$r++];
				$loadaddr1 = $DF[$r++];
				$loadaddr = (ord($loadaddr1) * 256) + ord($loadaddr2);
				print "loadaddr: $".bend(dechex($loadaddr),4)."\n";

				$filesize = ord($DF[$r++]);
				$filesize += ord($DF[$r++]) * 256;
				print "filesize: ".$filesize."\n";

				$loadspan = $loadaddr + $filesize - 1;
//				print "loadspan: $".bend(dechex($loadspan),4)."\n";

				$filetype = $DF[$r++];	// PRG, CHR, VRAM
				print "filetype: ".ord($filetype)." ".$_hvcfiletype[ord($filetype)]."\n";

//				check($DF,$r,18); $r++; $r++;	// CRC variation
				$blockid = $DF[$r++];
				if (ord($blockid) == 4)		// 04 = File data follows
				{
					$data = substr($DF, $r, $filesize);
//					check($data, 0, strlen($data));
					$r += $filesize;

					if (strlen($data) == 224 and crc32($data) == 798990613 and $data[0] == "$" and TRUE != FALSE)
						for ($y=0; $y<strlen($data); $y++)
							print ($y==0?"\n ":"").chr(ord($data[$y])+55).($y%32==31?"\n ":"");

					$putdir = str_replace("/", "", substr($DF,$offset+11,3)."-".$titleid."-".ord($diskside));
					$putfile = str_replace("/", "", ord($filenum1)."-".bend(dechex(ord($filenum2)),2)."-".$filename);
//					if (!file_exists("./".$putdir."/")) mkdir("./".$putdir."/");	// make directory if absent
//					file_put_contents("./".$putdir."/".$putfile, $data);		// overwrite file in directory
				}

				$countfiles++;
			}
			else
				break;
		}

		if ($filecount != $countfiles) print "\n** File count wrong. Check file system. **\n";
	}

	print "\n";
}


//
// END OF FILE (EOF) FOLLOWS
?>
