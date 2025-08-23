<?php
require_once 'horizon.php';

$config = new SkyConfig(51.285335, 9.787075);
$generator = new SkyGradientGenerator($config);

list($gradient, $topVec, $bottomVec) = $generator->generate();
$sunElevation = $generator->getSunElevation();

$top = "rgb({$topVec[0]}, {$topVec[1]}, {$topVec[2]})";
$bottom = "rgb({$bottomVec[0]}, {$bottomVec[1]}, {$bottomVec[2]})";

?><!DOCTYPE html>
<html lang="en" style="height: 100%; background: <?php echo htmlspecialchars($gradient); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Refresh" content="60">
    <meta name="theme-color" content="<?php echo htmlspecialchars($top); ?>">
    <meta name="darkreader-lock">
    <meta name="description" content="The current sky at location (<?php echo $config->latitude; ?>, <?php echo $config->longitude; ?>), rendered as a CSS gradient. Refreshes every minute using the meta http-equiv='Refresh' tag.">
    <title>Sky Gradient at <?php echo $config->latitude; ?>, <?php echo $config->longitude; ?></title>
</head>
<body style="margin: 0; padding: 0; height: 100vh;">

<header style="background-color: <?php echo htmlspecialchars($top); ?>;">

</header>

<div style="height: 100%; background: <?php echo htmlspecialchars($gradient); ?>">
    Sky Gradient at <?php echo $config->latitude; ?>, <?php echo $config->longitude; ?> <br>
    Sun Elevation: <?php echo htmlspecialchars($sunElevation); ?> rad <br>
</div>

<footer style="background-color: <?php echo htmlspecialchars($bottom); ?>;">

</footer>
</body>
</html>