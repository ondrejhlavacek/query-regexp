<?php
require('/code/vendor/autoload.php');

if (!getenv('KBC_STORAGE_API_TOKEN') || !getenv('KBC_TABLE_ID')) {
    print "Missing envs KBC_STORAGE_API_TOKEN or KBC_TABLE_ID\n";
    exit(1);
}

$client = new \Keboola\StorageApi\Client(["token" => getenv('KBC_STORAGE_API_TOKEN')]);
$temp = new \Keboola\Temp\Temp();
$fileInfo = $temp->createTmpFile('queries');
$exporter = new \Keboola\StorageApi\TableExporter($client);
$exporter->exportTable(getenv('KBC_TABLE_ID'), $fileInfo->getPathname(), ["gzip" => false]);
print "File downloaded\n";
$csv = new \Keboola\Csv\CsvFile($fileInfo->getPathname());
$csv->rewind();
$csv->next();
$re1 = '@(([\'"]).*?[^\\\]\2)|((?:\#|--).*?$|/\*(?:[^/*]|/(?!\*)|\*(?!/)|(?R))*\*\/)\s*|(?<=;)\s+@ms';
/*
$re2 = '/["\'][^"\']*(?!\\\\)["\'](*SKIP)(*F)       # Make sure we\'re not matching inside of quotes, double quotes and backticks
|(?m-s:\s*(?:\-{2}|\#)[^\n]*$) # Single line comment
|(?:
  \/\*.*?\*\/                  # Multi-line comment
  (?(?=(?m-s:[\t ]+$))         # Get trailing whitespace if any exists and only if it\'s the rest of the line
    [\t ]+
  )
)/xs';
*/

$count = 1;
$errors = 0;
$differ = new \SebastianBergmann\Diff\Differ();

while($csv->current()) {
    if ($count % 1000 == 0) {
        print "Row $count\n";
    }
    $row = $csv->current();
    list($projectId, $configId, $rowId, $backend, $queryNumber, $query) = $row;


    $result1tmp = trim(preg_replace($re1, '$1', $query));
    $result1Arr = [];

    $result2 = trim(SqlFormatter::removeComments($query));

    // manually strip lines beginning with a comment from old regexp
    foreach (explode("\n", $result1tmp) as $key => $line) {
        if (substr(ltrim($line), 0, 2) == '--') {
            continue;
        }
        if (substr(ltrim($line), 0, 1) == '#') {
            continue;
        }
        $result1Arr[] = $line;
    }
    $result1 = join("\n", $result1Arr);

    if (compressSQL($result1) != compressSQL($result2)) {
        file_put_contents("/code/report/{$errors}.original", $query);
        file_put_contents("/code/report/{$errors}.diff", $differ->diff(SqlFormatter::format($result1, false), SqlFormatter::format($result2, false)));
        file_put_contents("/code/report/{$errors}.regexp1", $result1);
        file_put_contents("/code/report/{$errors}.regexp2", $result2);
        file_put_contents("/code/report/{$errors}.meta", "row {$count}\n");
        $errors++;
    }

    $csv->next();
    $count++;
    if ($errors > 10) {
        print "Too many errors, aborting...\n";
        die(1);
    }
}

// remove ALL whitespace from queries
function compressSQL($query) {
    return str_replace(
        "\t",
        "",
        str_replace(
            "\n",
            "",
            str_replace(
                " ",
                "",
                $query
            )
        )
    );
}

// remove spaces and tabs
function removeWhiteSpaces($query) {
    return str_replace(
        "\t",
        "",
        str_replace(
            " ",
            "",
            $query
        )
    );
}
