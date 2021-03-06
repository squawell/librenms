<?php

/*
 * LibreNMS Adtran AOS RAM polling module
 *
 * Copyright (c) 2016 Chris A. Evans <thecityofguanyu@outlook.com>
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the
 * Free Software Foundation, either version 3 of the License, or (at your
 * option) any later version.  Please see LICENSE.txt at the top level of
 * the source code distribution for details.
*/


/*
/ ADTRAN-AOSCPU::adGenAOSMemPool.0 = Gauge32: 67108863
/ ADTRAN-AOSCPU::adGenAOSHeapSize.0 = Gauge32: 39853040
/ ADTRAN-AOSCPU::adGenAOSHeapFree.0 = Gauge32: 25979888
*/


$mempool['used']  = snmp_get($device, 'adGenAOSHeapSize.0', '-OvQ', 'ADTRAN-AOSCPU');
$mempool['total'] = snmp_get($device, 'adGenAOSMemPool.0', '-OvQ', 'ADTRAN-AOSCPU');
$mempool['free']  = ($mempool['total'] - $mempool['used']);
