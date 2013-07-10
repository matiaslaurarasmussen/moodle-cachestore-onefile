<?php
/**
 * Form for adding a onefile instance.
 *
 * @copyright  2013 Matias Rasmussen
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cachestore_onefile_addinstance_form extends cachestore_addinstance_form {

    protected function configuration_definition() {
        $form = $this->_form;

        $form->addElement('text', 'ttl', get_string('ttl', 'cachestore_onefile'));
        $form->setType('path', PARAM_INT);
	$form->setDefault("path",86400 ); // defaults to 1 day = 60*60*24
        $form->addHelpButton('ttl', 'ttl', 'cachestore_onefile');

    }
}

