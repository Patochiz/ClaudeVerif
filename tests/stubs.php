<?php
// Simulation minimale de price2num (MAIN_MAX_DECIMALS_UNIT=5, MAIN_MAX_DECIMALS_TOT=2 par défaut dans Dolibarr)
function price2num($amount, $rounding = '', $option = 0)
{
	$amount = (float) str_replace(',', '.', (string) $amount);
	if ($rounding === 'MU') return (string) round($amount, 5);
	if ($rounding === 'MT') return (string) round($amount, 2);
	return (string) $amount;
}
