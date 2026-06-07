<?php
/* Copyright (C) 2004-2018	Laurent Destailleur	<eldy@users.sourceforge.net>
 * Copyright (C) 2018-2019	Nicolas ZABOURI		<info@inovea-conseil.com>
 * Copyright (C) 2019-2024	Frederic France		<frederic.france@free.fr>
 * Copyright (C) 2026		Melody Meads GmbH
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * 	\defgroup   ledgerpilot     Module LedgerPilot
 *	\brief      LedgerPilot module descriptor.
 *
 *	\file       ledgerpilot/core/modules/modLedgerPilot.class.php
 *	\ingroup    ledgerpilot
 *	\brief      Description and activation file for module LedgerPilot
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';


/**
 *  Description and activation class for module LedgerPilot.
 *
 *  LedgerPilot is a separate, bank-agnostic module that links bank transactions
 *  (core llx_bank lines) to invoices and, for the rest, proposes an accounting
 *  account for a human to approve. It integrates with the bankimport module
 *  purely through data — the llx_bankimport_line_ref keystone side-table, read by
 *  joining FROM llx_bank (see docs/categorization-module-spec.md §4). There is no
 *  code coupling and no hard dependency, so the engine still runs (with weaker
 *  matching) on bank lines that never went through bankimport.
 */
class modLedgerPilot extends DolibarrModules
{
	/**
	 * Constructor. Defines names, constants, directories, menus and permissions.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf, $langs;

		$this->db = $db;

		// Unique module id (custom modules use the 500000+ range, reserved on the Dolibarr wiki).
		$this->numero = 500000;

		// Key used for permissions, menus and the enable/disable constant.
		$this->rights_class = 'ledgerpilot';

		// Grouping in the module setup page.
		$this->family = "financial";
		$this->module_position = '90';

		// Module label (no space). Translation keys ModuleLedgerPilotName/Desc derive from it.
		$this->name = "LedgerPilot";
		$this->description = "Automated bank-transaction categorization and posting";
		// descriptionlong is left unset on purpose: getDescLong() then falls back to README.md.

		$this->editor_name = 'Melody Meads GmbH';
		$this->editor_url = 'https://www.melodymeads.ch';

		$this->version = '0.1.0';

		// Constant in llx_const that flags the module enabled/disabled.
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);

		// Font Awesome picto (no image file needed).
		$this->picto = 'fa-robot';

		// No optional module parts yet (no triggers/hooks/css/js). The queue worker
		// and the review hooks arrive with the engine (spec steps 5-6).
		$this->module_parts = array();

		// No data directories needed yet.
		$this->dirs = array();

		// Setup page (gear icon on the module list). Real parameters (thresholds,
		// processor->clearing-account map) are added together with the engine.
		$this->config_page_url = array("setup.php@ledgerpilot");

		// Dependencies. A condition to hide the module from the list.
		$this->hidden = false;
		// No hard module dependency: integration with bankimport is via data only
		// (the keystone side-table), so LedgerPilot must load even when bankimport is off.
		$this->depends = array();
		$this->requiredby = array();
		$this->conflictwith = array();

		$this->langfiles = array("ledgerpilot@ledgerpilot");

		// Prerequisites.
		$this->phpmin = array(7, 4);
		$this->need_dolibarr_version = array(19, -3);
		$this->need_javascript_ajax = 0;

		// Nothing seeded into the database at activation yet.
		$this->const = array();
		$this->dictionaries = array();
		$this->boxes = array();

		// Cron jobs: the queue worker (one row per bank line, leased) is declared
		// here when the pipeline lands (spec step 6).
		$this->cronjobs = array();

		// Permissions: real read/approve rights are declared with the review
		// Dashboard (spec steps 5-6). None yet.
		$this->rights = array();

		// Top-menu entry so the module is reachable once enabled.
		$this->menu = array();
		$r = 0;
		$this->menu[$r++] = array(
			'fk_menu'  => '',
			'type'     => 'top',
			'titre'    => 'ModuleLedgerPilotName',
			'prefix'   => img_picto('', $this->picto, 'class="pictofixedwidth valignmiddle"'),
			'mainmenu' => 'ledgerpilot',
			'leftmenu' => '',
			'url'      => '/ledgerpilot/ledgerpilotindex.php',
			'langs'    => 'ledgerpilot@ledgerpilot',
			'position' => 1000 + $r,
			'enabled'  => "isModEnabled('ledgerpilot')",
			'perms'    => '1',
			'target'   => '',
			'user'     => 0,
		);
	}

	/**
	 *  Module activation. Loads this module's SQL (every llx_*.sql file under sql/,
	 *  with the llx_ prefix rewritten to the instance prefix), then registers
	 *  constants, menus and permissions. The loader tolerates already-existing
	 *  tables, so re-activation is safe. (The 4 engine tables land in sql/ at step 5.)
	 *
	 *  @param      string      $options    Options when enabling ('', 'noboxes', ...)
	 *  @return     int<-1,1>               1 on success, <= 0 on failure
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/ledgerpilot/sql/');
		if ($result < 0) {
			return -1;
		}

		$sql = array();
		return $this->_init($sql, $options);
	}

	/**
	 *  Module deactivation. Removes constants, menus and permissions. Module tables
	 *  are intentionally left in place so data survives a deactivate/reactivate cycle.
	 *
	 *  @param      string      $options    Options when disabling
	 *  @return     int<-1,1>               1 on success, <= 0 on failure
	 */
	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}
}
