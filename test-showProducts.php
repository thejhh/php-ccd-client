<?php

require("SendanorModel.class.php");

$ccd = new SendanorModel();
$ccd->setURL("https://secure.sendanor.fi/~jheusala/ccd-dev/ccd-server.cgi");

$list = $ccd->listProducts();

foreach($list as $item) {
	echo '#' . $item['product_id'] . ' | ' . $item['name'] . ' | ' . $item['price'] . ' | ' . $item['vat_procent'] . "\n";
}

?>
