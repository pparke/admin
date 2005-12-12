<?php

require_once 'Admin/pages/AdminIndex.php';

/**
 * Generic admin search page
 *
 * This class is intended to be a convenience base class. For a fully custom 
 * search page, inherit directly from AdminPage instead.
 *
 * @package Admin
 * @copyright silverorange 2004
 */
abstract class AdminSearch extends AdminIndex
{
	// process phase
	// {{{ protected function processInternal()

	protected function processInternal()
	{
		parent::processInternal();

		try {
			$form = $this->ui->getWidget('search_form');
			$form->process();

			if ($form->isProcessed())
				$this->saveState();

			if ($this->hasState()) {
				$this->loadState();
				$index = $this->ui->getWidget('results_frame');
				$index->visible = true;
			}
		} catch (SwatWidgetNotFoundException $e) {
		}
	}

	// }}}
	// {{{ protected function saveState()

	protected function saveState()
	{
		$search_form = $this->ui->getWidget('search_form');
		$search_state = $search_form->getDescendantStates();
		$_SESSION[$this->source.'_search_state'] = $search_state;
	}

	// }}}
	// {{{ protected function loadState()

	/**
	 * Loads a saved search state for this page
	 *
	 * @return boolean true if a saved state exists for this page and false if
	 *                  it does not.
	 *
	 * @see AdminSearchPage::hasState()
	 */
	protected function loadState()
	{
		$return = false;
		$search_form = $this->ui->getWidget('search_form');
		$key = $this->source.'_search_state';

		if ($this->hasState()) {
			$search_form->setDescendantStates($_SESSION[$key]);
			$return = true;
		}

		return $return;
	}

	// }}}
	// {{{ protected function hasState()

	/**
	 * Checks if this search page has stored search information
	 *
	 * @return boolean true if this page has stored search information and
	 *                  false if it does not.
	 */
	protected function hasState()
	{
		$key = $this->source.'_search_state';
		return isset($_SESSION[$key]);
	}

	// }}}

	// build phase
	// {{{ protected function buildInternal()

	protected function buildInternal()
	{
		parent::buildInternal();

		try {
			$form = $this->ui->getWidget('search_form', true);
			$form->action = $this->source;
		} catch (SwatWidgetNotFoundException $e) {
		}
	}

	// }}}
	// {{{ protected function buildViews()

	/**
	 * Builds views for this search page
	 *
	 * View models are initialized to an empty table store unless a saved
	 * search state is available.
	 */
	protected function buildViews()
	{
		if ($this->hasState()) {
			parent::buildViews();
		} else {
			$root = $this->ui->getRoot();
			$views = $root->getDescendants('SwatTableView');
			foreach ($views as $view)
				$view->model = new SwatTableStore();
		}
	}

	// }}}
}

?>
