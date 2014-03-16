<?php

/**
 * @package			ASN
 * @copyright		Copyright (C) 2012 S.D.O.C. LTD. All rights reserved.
 * @license			GNU General Public License version 2 or later; see LICENSE.txt
 */
class Asn_Type_NumStr extends Asn_Object {

	/**
	 * Parse the ASN data of a primitive type.
	 * (Override this to parse specific types)
	 * @param $data The ASN encoded data
	 */
	protected function parse($data) {
		parent::parse($data);
// 		$numarr = unpack("C*",$data);
// 		$tempData =0;
// 		foreach($numarr as $byte) {
// 			$tempData =  ($tempData << 8 )+ $byte;
// 		}
//
// 		$this->parsedData = $tempData;
	}

}
