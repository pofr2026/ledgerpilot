<?php
/* Copyright (C) 2026		Melody Meads GmbH
 * Copyright (C) 2025		Frederic France		<frederic.france@free.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    ledgerpilot/lib/ledgerpilot.lib.php
 * \ingroup ledgerpilot
 * \brief   Common functions for the LedgerPilot module.
 */

/**
 * Prepare the array of tabs for the module's admin pages.
 *
 * @return array<array{string,string,string}> Head array for dol_get_fiche_head()
 */
function ledgerpilotAdminPrepareHead()
{
	global $langs, $conf;

	$langs->load("ledgerpilot@ledgerpilot");

	$h = 0;
	$head = array();

	$head[$h][0] = dolBuildUrl(dol_buildpath("/ledgerpilot/admin/setup.php", 1));
	$head[$h][1] = $langs->trans("Settings");
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dolBuildUrl(dol_buildpath("/ledgerpilot/admin/about.php", 1));
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;

	// Let other modules add or remove tabs on these admin pages.
	complete_head_from_modules($conf, $langs, null, $head, $h, 'ledgerpilot@ledgerpilot');
	complete_head_from_modules($conf, $langs, null, $head, $h, 'ledgerpilot@ledgerpilot', 'remove');

	return $head;
}
