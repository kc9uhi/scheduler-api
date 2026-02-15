<?php

/* This function from WaveLog -- https://github.com/wavelog/wavelog
*  MIT License
*
*  Copyright (c) 2019 Peter Goodhall
*  Copyright (c) 2024 by DF2ET, DJ7NT, HB9HIL, LA8AJA
*  
*  Permission is hereby granted, free of charge, to any person obtaining a copy
*  of this software and associated documentation files (the "Software"), to deal
*  in the Software without restriction, including without limitation the rights
*  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
*  copies of the Software, and to permit persons to whom the Software is
*  furnished to do so, subject to the following conditions:
*
*  The above copyright notice and this permission notice shall be included in all
*  copies or substantial portions of the Software.
*
*  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
*  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
*  FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
*  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
*  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
*  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
*  SOFTWARE.
*/

function qra2latlong($strQRA) {
	$strQRA = preg_replace('/\s+/', '', $strQRA);
	if (substr_count($strQRA, ',') > 0) {
		if (substr_count($strQRA, ',') == 3) {
			// Handle grid corners
			$grids = explode(',', $strQRA);
			$gridlengths = array(strlen($grids[0]), strlen($grids[1]), strlen($grids[2]), strlen($grids[3]));
			$same = array_count_values($gridlengths);
			if (count($same) != 1) {
				return false;
			}
			$coords = array(0, 0);
			for ($i = 0; $i < 4; $i++) {
				$cornercoords[$i] = qra2latlong($grids[$i]);
				$coords[0] += $cornercoords[$i][0];
				$coords[1] += $cornercoords[$i][1];
			}
			return array(round($coords[0] / 4), round($coords[1] / 4));
		} else if (substr_count($strQRA, ',') == 1) {
			// Handle grid lines
			$grids = explode(',', $strQRA);
			if (strlen($grids[0]) != strlen($grids[1])) {
				return false;
			}
			$coords = array(0, 0);
			for ($i = 0; $i < 2; $i++) {
				$linecoords[$i] = qra2latlong($grids[$i]);
			}
			if ($linecoords[0][0] != $linecoords[1][0]) {
				$coords[0] = round((($linecoords[0][0] + $linecoords[1][0]) / 2), 1);
			} else {
				$coords[0] = round($linecoords[0][0], 1);
			}
			if ($linecoords[0][1] != $linecoords[1][1]) {
				$coords[1] = round(($linecoords[0][1] + $linecoords[1][1]) / 2);
			} else {
				$coords[1] = round($linecoords[0][1]);
			}
			return $coords;
		} else {
			return false;
		}
	}

	if ((strlen($strQRA) % 2 == 0) && (strlen($strQRA) <= 10)) {	// Check if QRA is EVEN (the % 2 does that) and smaller/equal 8
		$strQRA = strtoupper($strQRA);
		if (strlen($strQRA) == 2)  $strQRA .= "55";	// Only 2 Chars? Fill with center "55"
		if (strlen($strQRA) == 4)  $strQRA .= "LL";	// Only 4 Chars? Fill with center "LL" as only A-R allowed
		if (strlen($strQRA) == 6)  $strQRA .= "55";	// Only 6 Chars? Fill with center "55"
		if (strlen($strQRA) == 8)  $strQRA .= "LL";	// Only 8 Chars? Fill with center "LL" as only A-R allowed

		if (!preg_match('/^[A-R]{2}[0-9]{2}[A-X]{2}[0-9]{2}[A-X]{2}$/', $strQRA)) {
			return false;
		}

		list($a, $b, $c, $d, $e, $f, $g, $h, $i, $j) = str_split($strQRA, 1);	// Maidenhead is always alternating. e.g. "AA00AA00AA00" - doesn't matter how deep. 2 chars, 2 numbers, etc.
		$a = ord($a) - ord('A');
		$b = ord($b) - ord('A');
		$c = ord($c) - ord('0');
		$d = ord($d) - ord('0');
		$e = ord($e) - ord('A');
		$f = ord($f) - ord('A');
		$g = ord($g) - ord('0');
		$h = ord($h) - ord('0');
		$i = ord($i) - ord('A');
		$j = ord($j) - ord('A');

		$nLong = ($a * 20) + ($c * 2) + ($e / 12) + ($g / 120) + ($i / 2880) - 180;
		$nLat = ($b * 10) + $d + ($f / 24) + ($h / 240) + ($j / 5760) - 90;

		$arLatLong = array($nLat, $nLong);
		return $arLatLong;
	} else {
		return array(0, 0);
	}
}

/* End of file Qra.php */
?>