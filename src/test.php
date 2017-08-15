<?php
require('/code/vendor/autoload.php');

if (!isset($argv[1])) {
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
} else {
    $csv = new \Keboola\Csv\CsvFile($argv[1]);
}

$limit = null;
if (isset($argv[2])) {
    $limit = $argv[2];
}

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
    if ($limit !== null && $count >= $limit) {
        print "Hitting limit $limit\n";
        exit(0);
    }
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

    if ($result1 != $result2
        && compressSQL($result1) != compressSQL($result2)
        && compressSQL(stripSingleLineInlineComments($result1)) != compressSQL(stripSingleLineInlineComments($result2))
        && compressSQL(stripMultiLineComments(stripSingleLineInlineComments($result1))) != compressSQL(stripMultiLineComments(stripSingleLineInlineComments($result2)))
    ) {
        file_put_contents("/code/report/{$errors}.original", $query);
        file_put_contents("/code/report/{$errors}.diff", $differ->diff(SqlFormatter::format($result1, false), SqlFormatter::format($result2, false)));
        file_put_contents("/code/report/{$errors}.result1", $result1);
        file_put_contents("/code/report/{$errors}.result1-formatted", SqlFormatter::format($result1, false));
        file_put_contents("/code/report/{$errors}.result2", $result2);
        file_put_contents("/code/report/{$errors}.result2-formatted", SqlFormatter::format($result2, false));
        file_put_contents("/code/report/{$errors}.meta", "line {$count}\nproject {$projectId}\nconfig {$configId}\nrow {$rowId}\nqueryNumber {$queryNumber}");
        $errors++;
    }

    $csv->next();
    $count++;
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

function stripSingleLineInlineComments($query) {
    $result = [];
    foreach (explode("\n", $query) as $key => $line) {
        if (strpos($line, '--')) {
            $line = substr($line, 0, strpos($line, '--') - 1);
        }
        if (strpos($line, '#')) {
            $line = substr($line, 0, strpos($line, '#') - 1);
        }
        $result[] = $line;
    }
    return join("\n", $result);
}

function stripMultiLineComments($query) {
    $re = '/\/\*.*?\*\//ms';
    return preg_replace($re, '', $query);
}
