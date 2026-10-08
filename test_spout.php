<?php
require 'vendor/autoload.php';
use OpenSpout\Writer\XLSX\Writer;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Cell;

try {
    $w = new Writer();
    $w->openToFile('test_output.xlsx');
    $w->addRow(Row::fromValues(['A']));
    $w->close();
    echo "OK";
} catch (\Throwable $e) {
    echo $e->getMessage();
}
