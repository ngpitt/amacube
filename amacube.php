<?php

class amacube extends rcube_plugin
{
    // All tasks excluding 'login' and 'logout'
    public 	$task 		= '?(?!login|logout).*';
    private	$rc;
    private	$amacube;
    public  $ama_admin;

    function init() {
   	$this->rc = rcmail::get_instance();
	$this->amacube = new stdClass;
    // Load plugin config
    $this->load_config();
	// Amacube storage on rcmail instance
	$this->rc->amacube = new stdClass;
	$this->rc->amacube->errors = array();
	$this->rc->amacube->feedback = array();
	// Check accounts database for catchall enabled
		if ($this->rc->config->get('amacube_accounts_db_dsn')) {
			include_once('AccountConfig.php');
			$this->amacube->account = new AccountConfig($this->rc->config->get('amacube_accounts_db_dsn'));
			// Check for account filter
			if ($this->amacube->account->initialized && isset($this->amacube->account->filter)) {
				// Store on rcmail instance
				$this->rc->amacube->filter 		= $this->amacube->account->filter;
			}
			// Check for account catchall
			if ($this->amacube->account->initialized && isset($this->amacube->account->catchall)) {
				// Store on rcmail instance
				$this->rc->amacube->catchall 	= $this->amacube->account->catchall;
			}
		}
		// Load amavis config
        include_once('AmavisConfig.php');
        $this->amacube->config = new AmavisConfig($this->rc->config->get('amacube_db_dsn'));
		// Check for user & auto create option (disable plugin)
		if (!$this->amacube->config->initialized && $this->rc->config->get('amacube_auto_create_user') !== true) { return; }
		// Check for writing default user & config
		if (!$this->amacube->config->initialized && $this->rc->config->get('amacube_auto_create_user') === true) {
			// Check accounts database for filter enabled
			if (isset($this->rc->amacube->filter) && $this->rc->amacube->filter == false) { return; }
			// Write default user & config
			if ($this->amacube->config->write_to_db()) {
    				$this->rc->amacube->feedback[] = array('type' => 'confirmation', 'message' => 'policy_default_message');
			}
		}

        $this->ama_admin = false;
        foreach ( $this->rc->config->get('amacube_amavis_admins') as $s_admin ) {
            if ( strtolower($s_admin) == strtolower($this->rc->user->data['username']) ) {
                $this->ama_admin = true;
            }
        }
		// Add localization
        $this->add_texts('localization/', true);
        // Register tasks & actions
        $this->register_action('plugin.amacube-settings', array($this, 'settings_init'));
		$this->register_task('quarantine');
		$this->register_action('amacube-quarantine', array($this, 'quarantine_init'));

		// Initialize GUI
        $this->add_hook('startup', array($this, 'gui_init'));
		// Send feedback
		$this->feedback();
    }
	// Initialize GUI
    function gui_init()
    {
    	$this->rc = rcmail::get_instance();
       	$this->add_hook('settings_actions', array($this, 'settings_actions'));

        // Add taskbar button
        $this->add_button(array(
            'command'    => 'quarantine',
            'type'       => 'link',
            'class'      => 'button files',
            'classsel'   => 'button files selected',
            'innerclass' => 'inner',
            'label'      => 'amacube.quarantine',
        ), 'taskbar');
		// Add javascript
        $this->include_script('amacube.js');
        // Add stylesheet
        $skin_path = $this->local_skin_path();
        if (is_file($this->home . "/$skin_path/amacube.css")) {
            $this->include_stylesheet("$skin_path/amacube.css");
        }
    }

	// Register as settings action
    function settings_actions($args)
    {
        $args['actions'][] = array(
		'action' => 'plugin.amacube-settings',
		'class' => 'filter',
		'label' => 'amacube.filter_settings_pagetitle',
		'title' => 'amacube.filter_settings_pagetitle',
		'domain' => 'amacube'
		);

        return $args;
    }

    // Initialize settings task
    function settings_init()
    {
    	$this->rc = rcmail::get_instance();
        // Use standard plugin page template
        $this->register_handler('plugin.body', array($this, 'settings_display'));
        $this->rc->output->set_pagetitle(rcube_utils::rep_specialchars_output($this->gettext('filter_settings_pagetitle'), 'html', 'strict', true));
        $this->rc->output->send('plugin');
    }
    // Initialize quarantine task
    function quarantine_init()
    {
		if (rcube_utils::get_input_value('_remote', rcube_utils::INPUT_POST, false) == 1) {
			// Client pagination request
			$this->quarantine_display(true);
		} else {
			// Client page request
	        $this->register_handler('plugin.countdisplay', array($this, 'quarantine_display_count'));
	        $this->register_handler('plugin.body', array($this, 'quarantine_display'));
	        $this->rc->output->set_pagetitle(rcube_utils::rep_specialchars_output($this->gettext('quarantine_pagetitle'), 'html', 'strict', true));
			// Use amacube quarantine page template
			$this->rc->output->send('amacube.quarantine');
		}
    }
    // Display settings action
    function settings_display()
    {
    	$this->rc = rcmail::get_instance();
		// Include settings class
		if (!$this->amacube->config) {
        	include_once('AmavisConfig.php');
        	$this->amacube->config = new AmavisConfig($this->rc->config->get('amacube_db_dsn'));
		}
		// Parse form
		if (rcube_utils::get_input_value('_token', rcube_utils::INPUT_POST, false)) { $this->settings_post(); }

        // Create output
        $output = '';
		// Add header to output
		$output .= html::tag('h1', array('class' => 'boxtitle'), rcube_utils::rep_specialchars_output($this->gettext('filter_settings_pagetitle'), 'html', 'strict', true));

        // Create output : table (checks)
        $output_table = new html_table(array('cols' => 2, 'cellpadding' => 3, 'class' => 'propform'));
        // Create output : table : checkbox : spam check
        $output_table->add('title', html::label('activate_spam_check', $this->gettext('spam_check')));
		$output_table->add('',$this->_show_checkbox('activate_spam_check', $this->amacube->config->is_active('spam')));
		// Create output : table : checkbox : virus check
        $output_table->add('title', html::label('activate_virus_check', $this->gettext('virus_check')));
		$output_table->add('',$this->_show_checkbox('activate_virus_check', $this->amacube->config->is_active('virus')));
		// Create output : fieldset
		$output_legend = html::tag('legend', null , $this->gettext('section_checks'));
		$output_fieldset = html::tag('fieldset', array('class' => 'checks'),$output_legend.$output_table->show());
		// Create output : activate
		$output_checks = $output_fieldset;

        // Create output : table (delivery)
        $output_table = new html_table(array('cols' => 2, 'cellpadding' => 3, 'class' => 'propform'));
		// Create output : table : radios : spam
		$output_table->add('title', $this->gettext('spam_delivery'));
		$string = '';
        $string .= $this->_show_radio('spam_delivery_deliver','spam_delivery', 'deliver',$this->amacube->config->is_delivery('spam','deliver')).' ';
		$string .= html::label('spam_delivery_deliver', $this->gettext('deliver'));
		$string .= $this->_show_radio('spam_delivery_quarantine','spam_delivery', 'quarantine',$this->amacube->config->is_delivery('spam','quarantine')).' ';
		$string .= html::label('spam_delivery_quarantine', $this->gettext('quarantine'));
		$string .= $this->_show_radio('spam_delivery_discard','spam_delivery', 'discard',$this->amacube->config->is_delivery('spam','discard'));
		$string .= html::label('spam_delivery_discard', $this->gettext('discard'));
		$output_table->add('',$string);
        // Create output : table : radios : virus
        $output_table->add('title', $this->gettext('virus_delivery'));
		$string = '';
        $string .= $this->_show_radio('virus_delivery_deliver','virus_delivery', 'deliver',$this->amacube->config->is_delivery('virus','deliver')).' ';
		$string .= html::label('virus_delivery_deliver', $this->gettext('deliver'));
		$string .= $this->_show_radio('virus_delivery_quarantine','virus_delivery', 'quarantine',$this->amacube->config->is_delivery('virus','quarantine')).' ';
		$string .= html::label('virus_delivery_quarantine', $this->gettext('quarantine'));
		$string .= $this->_show_radio('virus_delivery_discard','virus_delivery', 'discard',$this->amacube->config->is_delivery('virus','discard'));
		$string .= html::label('virus_delivery_discard', $this->gettext('discard'));
		$output_table->add('',$string);
        // Create output : table : radios : banned
        $output_table->add('title', $this->gettext('banned_delivery'));
		$string = '';
        $string .= $this->_show_radio('banned_delivery_deliver','banned_delivery', 'deliver',$this->amacube->config->is_delivery('banned','deliver')).' ';
		$string .= html::label('banned_delivery_deliver', $this->gettext('deliver'));
		$string .= $this->_show_radio('banned_delivery_quarantine','banned_delivery', 'quarantine',$this->amacube->config->is_delivery('banned','quarantine')).' ';
		$string .= html::label('banned_delivery_quarantine', $this->gettext('quarantine'));
		$string .= $this->_show_radio('banned_delivery_discard','banned_delivery', 'discard',$this->amacube->config->is_delivery('banned','discard'));
		$string .= html::label('banned_delivery_discard', $this->gettext('discard'));
		$output_table->add('',$string);
        // Create output : table : radios : bad_header
        $output_table->add('title', $this->gettext('bad_header_delivery'));
		$string = '';
        $string .= $this->_show_radio('badheader_delivery_deliver','badheader_delivery', 'deliver',$this->amacube->config->is_delivery('bad_header','deliver')).' ';
		$string .= html::label('badheader_delivery_deliver', $this->gettext('deliver'));
		$string .= $this->_show_radio('badheader_delivery_quarantine','badheader_delivery', 'quarantine',$this->amacube->config->is_delivery('bad_header','quarantine')).' ';
		$string .= html::label('badheader_delivery_quarantine', $this->gettext('quarantine'));
		$string .= $this->_show_radio('badheader_delivery_discard','badheader_delivery', 'discard',$this->amacube->config->is_delivery('bad_header','discard'));
		$string .= html::label('badheader_delivery_discard', $this->gettext('discard'));
		$output_table->add('',$string);


		// Create output : fieldset
		$output_legend = html::tag('legend', null, $this->gettext('section_delivery'));
		$output_fieldset = html::tag('fieldset', array('class' => 'delivery'),$output_legend.$output_table->show());
		// Create output : quarantine
		$output_delivery = $output_fieldset;

        // Create output : table (levels)
        $output_table = new html_table(array('cols' => 2, 'cellpadding' => 3, 'class' => 'propform'));
		// Create output : table : input : sa_tag2_level
        $output_table->add('title', html::label('spam_tag2_level', $this->gettext('spam_tag2_level')));
        $output_table->add('',$this->_show_inputfield('spam_tag2_level', $this->amacube->config->policy_setting['spam_tag2_level']));
		// Create output : table : input : sa_kill_level
		$output_table->add('title', html::label('spam_kill_level', $this->gettext('spam_kill_level')));
        $output_table->add('',$this->_show_inputfield('spam_kill_level', $this->amacube->config->policy_setting['spam_kill_level']));
		// Create output : table : input : sa_cutoff_level
		$output_table->add('title', html::label('spam_quarantine_cutoff_level', $this->gettext('spam_quarantine_cutoff_level')));
        $output_table->add('',$this->_show_inputfield('spam_quarantine_cutoff_level', $this->amacube->config->policy_setting['spam_quarantine_cutoff_level']));
		// Create output : fieldset
		$output_legend = html::tag('legend', null, $this->gettext('section_levels'));
		$output_fieldset = html::tag('fieldset', array('class' => 'levels'),$output_legend.$output_table->show());
		// Create output : levels
		$output_levels = $output_fieldset;

		// Create output : button
		$output_button = html::div('footerleft formbuttons',$this->rc->output->button(array(
            'command' => 'plugin.amacube-settings-post',
            'type' => 'input',
            'class' => 'button mainaction',
            'label' => 'save'
        )));


		// Add form to container and container to output
        $output_form .= html::div(array('id' => 'preferences-details', 'class' => 'boxcontent'),$this->rc->output->form_tag(array(
            'id' => 'amacubeform',
            'name' => 'amacubeform',
            'class' => 'propform',
            'method' => 'post',
            'action' => './?_task=settings&_action=plugin.amacube-settings',
        ), $output_checks.$output_delivery.$output_levels));
        // Add labels to client
        $this->rc->output->add_label(
                'amacube.activate_spam_check',
                'amacube.activate_virus_check',
                'amacube.activate_spam_quarantine',
                'amacube.activate_virus_quarantine',
                'amacube.activate_banned_quarantine',
                'amacube.spam_tag2_level',
                'amacube.spam_kill_level'
        );
        // Add form to client
        $this->rc->output->add_gui_object('amacubeform', 'amacubeform');
		// Add button to output
		$output_form .= $output_button;
		$output .= html::div(array('id' => 'preferences-wrapper', 'class' => 'scrollable'),$output_form);
		// Send feedback
		$this->feedback();
		// Return output
		return $output;
    }

    // Save settings action
    function settings_post()
    {
        // Get the checks post vars
        $activate_spam_check 			= rcube_utils::get_input_value('activate_spam_check', rcube_utils::INPUT_POST, false);
        $activate_virus_check 			= rcube_utils::get_input_value('activate_virus_check', rcube_utils::INPUT_POST, false);
		// Get the levels post vars
        $spam_tag2_level 				= rcube_utils::get_input_value('spam_tag2_level', rcube_utils::INPUT_POST, false);
        $spam_kill_level 				= rcube_utils::get_input_value('spam_kill_level', rcube_utils::INPUT_POST, false);
		$spam_quarantine_cutoff_level	= rcube_utils::get_input_value('spam_quarantine_cutoff_level', rcube_utils::INPUT_POST, false);
        // Apply the checks post vars
        if (!empty($activate_spam_check)) { $this->amacube->config->policy_setting['bypass_spam_checks'] = false;}
        else { $this->amacube->config->policy_setting['bypass_spam_checks'] = true; }
        if (!empty($activate_virus_check)) { $this->amacube->config->policy_setting['bypass_virus_checks'] = false; }
        else { $this->amacube->config->policy_setting['bypass_virus_checks'] = true; }
		// Apply the delivery post vars
		foreach (array('spam_delivery','virus_delivery','banned_delivery','badheader_delivery') as $input) {
			$method 	= rcube_utils::get_input_value($input, rcube_utils::INPUT_POST, false);
			if ($method) {
				$delivery 	= explode('_', $input);
				$delivery 	= $delivery[0];
				if ($delivery == 'banned') { $lover = $delivery.'_files'; }
				elseif ($delivery == 'badheader') { $lover = 'bad_header'; $delivery = 'bad_header'; }
				else { $lover = $delivery; }
				switch ($method) {
					case 'deliver':
						$this->amacube->config->policy_setting[$lover.'_lover'] = true;
						$this->amacube->config->policy_setting[$delivery.'_quarantine_to'] = false;
						break;
					case 'quarantine':
						$this->amacube->config->policy_setting[$lover.'_lover'] = false;
						$this->amacube->config->policy_setting[$delivery.'_quarantine_to'] = true;
						break;
					case 'discard':
						$this->amacube->config->policy_setting[$lover.'_lover'] = false;
						$this->amacube->config->policy_setting[$delivery.'_quarantine_to'] = false;
						break;
				}
			}
		}
        // Apply the levels post vars
        if (!is_numeric($spam_tag2_level) || $spam_tag2_level < -20 || $spam_tag2_level > 20) {
            $this->rc->amacube->errors[] = 'spam_tag2_level_error';
        } else {
        	$this->amacube->config->policy_setting['spam_tag2_level'] = $spam_tag2_level;
        }
        if (!is_numeric($spam_kill_level) || $spam_kill_level < -20 || $spam_kill_level > 20) {
            $this->rc->amacube->errors[] = 'spam_kill_level_error';
        } else {
        	$this->amacube->config->policy_setting['spam_kill_level'] = $spam_kill_level;
        }
        if (!is_numeric($spam_quarantine_cutoff_level) || $spam_quarantine_cutoff_level < $this->amacube->config->policy_setting['spam_kill_level'] || $spam_kill_level > 1000) {
            $this->rc->amacube->errors[] = 'spam_quarantine_cutoff_level_error';
        } else {
        	$this->amacube->config->policy_setting['spam_quarantine_cutoff_level'] = $spam_quarantine_cutoff_level;
        }
        // Verify policy config
        if ($this->amacube->config->verify_policy_array() && $this->amacube->config->write_to_db()) {
			$this->rc->amacube->feedback[] = array('type' => 'confirmation', 'message' => 'config_saved');
        }

    }

	// Display quarantine task
	// Used to display entire page or specified range (ajax pagination)
    function quarantine_display($ajax = false) {

    	$ajax = ($ajax === true) ? true : false;
		// Include quarantine class
        include_once('AmavisQuarantine.php');
        $this->amacube->quarantine = new AmavisQuarantine($this->rc->config->get('amacube_db_dsn'),
                                                 $this->rc->config->get('amacube_amavis_host'),
                                                 $this->rc->config->get('amacube_amavis_port'));
		// Parse form
		if (rcube_utils::get_input_value('_token', rcube_utils::INPUT_POST, false)) { $this->quarantine_post(); }

		$pagination = array();
		if (!$ajax) {
			$output 				= '';
	        // Get all quarantines (0:0)
	        // Used to calculate pagination based on total amount of quarantined messages
			$pagination['start']	= 0;
			$pagination['size']		= 0;
		} else {
			$output 				= array();
			// Get paged quarantines
			$pagination['current']	= rcube_utils::get_input_value('page', rcube_utils::INPUT_POST, false) ?: 1;
			$pagination['total'] 	= rcube_utils::get_input_value('msgcount', rcube_utils::INPUT_POST, false);
			if (!$pagination['current'] || !$pagination['total']) {	return; }

			$pagination['current']	= (int) $pagination['current'];
			$pagination['total'] 	= (int) $pagination['total'];
			$pagination['size']		= $this->rc->config->get('mail_pagesize');
			$pagination['count']	= ceil(($pagination['total'] / $pagination['size']));
			$pagination['start']	= (($pagination['current'] * $pagination['size']) - $pagination['size']);
			$pagination['stop']		= ($pagination['start'] + $pagination['size']);
		}
		$quarantines = $this->amacube->quarantine->list_quarantines($pagination['start'],$pagination['size']);
        if (!is_array($quarantines)) {
			// Send feedback
			$this->feedback();
			// Return on error
            return;
        }
        if (count($quarantines) == 0) {
        	$this->amacube->feedback[] = array('type' => 'notice', 'message' => 'quarantine_no_result');
       	}
		if (!$ajax) {
			$pagination['current'] 	= 1;
			$pagination['size']		= $this->rc->config->get('mail_pagesize');
			$pagination['count']	= ceil((count($quarantines) / $pagination['size']));
			$pagination['start']	= (($pagination['current'] * $pagination['size']) - $pagination['size']);
			$pagination['stop']		= ($pagination['start'] + $pagination['size']);
			$pagination['total'] 	= count($quarantines);
		}
		// Pagination string
		$pagination['begin'] 		= ($pagination['start']+1);
		$pagination['end'] 			= ($pagination['total'] <= $pagination['size']) ? $pagination['total'] : (($pagination['stop'] > $pagination['total']) ? $pagination['total'] : $pagination['stop']);
		if (count($quarantines) == 0) {
			$string					= rcube_utils::rep_specialchars_output($this->gettext('quarantine_no_result'), 'html', 'strict', true);
		} else {
			$string					= rcube_utils::rep_specialchars_output($this->gettext('messages'), 'html', 'strict', true).' '.$pagination['begin'].' '.rcube_utils::rep_specialchars_output($this->gettext('to'), 'html', 'strict', true).' '.$pagination['end'].' '.rcube_utils::rep_specialchars_output($this->gettext('of'), 'html', 'strict', true).' '.$pagination['total'];
		}
		if (!$ajax) {
			// Store locally for template use (js include not loaded yet; command unavailable)
			$this->rc->amacube->pagecount_string = $string;
		} else {
			$this->rc->output->command('amacube.messagecount',$string);
		}
		// Pagination env
		$this->rc->output->set_env('page', $pagination['current']);
		$this->rc->output->set_env('pagecount', $pagination['count']);
		$this->rc->output->set_env('msgcount', $pagination['total']);
		// Create output
		if (!$ajax) {
	        // Create output : header table
            if ( $this->ama_admin === true ) {
                $messages_table = new html_table(array(
                'cols' 				=> 8,
                'id'				=> 'messagelist',
                'class' 			=> 'records-table messagelist sortheader fixedheader quarantine-messagelist'
                ));
            } else {
                $messages_table = new html_table(array(
                'cols' 				=> 7,
                'id'				=> 'messagelist',
                'class' 			=> 'records-table messagelist sortheader fixedheader quarantine-messagelist'
                ));
            }
	        // Create output : table : headers
	        $messages_table->add_header('release',rcube_utils::rep_specialchars_output($this->gettext('release'), 'html', 'strict', true));
	        $messages_table->add_header('delete',rcube_utils::rep_specialchars_output($this->gettext('delete'), 'html', 'strict', true));
	        $messages_table->add_header('received',rcube_utils::rep_specialchars_output($this->gettext('received'), 'html', 'strict', true));
	        $messages_table->add_header('subject',rcube_utils::rep_specialchars_output($this->gettext('subject'), 'html', 'strict', true));
	        $messages_table->add_header('sender',rcube_utils::rep_specialchars_output($this->gettext('sender'), 'html', 'strict', true));
if ( $this->ama_admin === true ) { $messages_table->add_header('recipient',rcube_utils::rep_specialchars_output($this->gettext('recipient'), 'html', 'strict', true)); }
	        $messages_table->add_header('type',rcube_utils::rep_specialchars_output($this->gettext('mailtype'), 'html', 'strict', true));
	        $messages_table->add_header('level',rcube_utils::rep_specialchars_output($this->gettext('spamlevel'), 'html', 'strict', true));
		}
		// Create output : table : rows
        foreach ($quarantines as $key => $value) {
        	if (!$ajax) {
	        	if ($key >= $pagination['start'] && $key < $pagination['stop']) {
		            $messages_table->add('release',$this->_show_radio('rel_'.$quarantines[$key]['id'],$quarantines[$key]['id'],'_rel_'.$quarantines[$key]['id']));
		            $messages_table->add('delete',$this->_show_radio('del_'.$quarantines[$key]['id'],$quarantines[$key]['id'],'_del_'.$quarantines[$key]['id']));
		            $messages_table->add('date',rcube_utils::rep_specialchars_output(date('Y-m-d H:i:s',$quarantines[$key]['received']), 'html', 'strict', true));
		            $messages_table->add('subject', $quarantines[$key]['subject'] ? rcube_utils::rep_specialchars_output($quarantines[$key]['subject'], 'html', 'strict', true) : $this->gettext('no subject'));
		            $messages_table->add('sender',rcube_utils::rep_specialchars_output($quarantines[$key]['sender'], 'html', 'strict', true));
if ( $this->ama_admin === true ) { $messages_table->add('recipient',rcube_utils::rep_specialchars_output($quarantines[$key]['recipient'], 'html', 'strict', true)); }
		            $messages_table->add('type',rcube_utils::rep_specialchars_output($this->gettext('content_decode_'.$quarantines[$key]['content']), 'html', 'strict', true));
		            $messages_table->add('level',rcube_utils::rep_specialchars_output($quarantines[$key]['level'], 'html', 'strict', true));
	        	}
        	} else {
				$string 			= '<tr>';
				$string				.= '<td class="release">'.$this->_show_radio('rel_'.$quarantines[$key]['id'],$quarantines[$key]['id'],'_rel_'.$quarantines[$key]['id']).'</td>';
				$string				.= '<td class="delete">'.$this->_show_radio('del_'.$quarantines[$key]['id'],$quarantines[$key]['id'],'_del_'.$quarantines[$key]['id']).'</td>';
				$string				.= '<td class="date">'.rcube_utils::rep_specialchars_output(date('Y-m-d H:i:s',$quarantines[$key]['received']), 'html', 'strict', true).'</td>';
				$string				.= '<td class="subject">'.($quarantines[$key]['subject'] ? rcube_utils::rep_specialchars_output($quarantines[$key]['subject'], 'html', 'strict', true) : $this->gettext('no subject')).'</td>';
				$string				.= '<td class="sender">'.rcube_utils::rep_specialchars_output($quarantines[$key]['sender'], 'html', 'strict', true).'</td>';
if ( $this->ama_admin === true ) { $string .= '<td class="recipient">'.rcube_utils::rep_specialchars_output($quarantines[$key]['recipient'], 'html', 'strict', true).'</td>'; }
				$string				.= '<td class="type">'.rcube_utils::rep_specialchars_output($this->gettext('content_decode_'.$quarantines[$key]['content']), 'html', 'strict', true).'</td>';
				$string				.= '<td class="level">'.rcube_utils::rep_specialchars_output($quarantines[$key]['level'], 'html', 'strict', true).'</td>';
				$string				.= '</tr>';
				$output[]			= $string;
        	}
        }
		if (!$ajax) {
			// Create output : table form
	        $output_table_form = $this->rc->output->form_tag(array(
	            'id' => 'quarantineform',
	            'name' => 'quarantineform',
	            'method' => 'post',
	            'action' => './?_task=quarantine&_action=amacube-quarantine',
			), $messages_table->show());
			// Add table container form to output
			$output .= $output_table_form;
	        // Add form to client
	        $this->rc->output->add_gui_object('quarantineform', 'quarantineform');
		} else {
			// Send list command
			$this->rc->output->command('amacube.messagelist',array('messages' => $output));
			// Send page commands
			if ($pagination['current'] > 1) {
				// Enable first & previous
				$this->rc->output->command('amacube.page','first','enabled');
				$this->rc->output->command('amacube.page','previous','enabled');
			} else {
				// Disable first & previous
				$this->rc->output->command('amacube.page','first','disabled');
				$this->rc->output->command('amacube.page','previous','disabled');
			}
			if ($pagination['current'] < $pagination['count']) {
				// Enable next & last
				$this->rc->output->command('amacube.page','next','enabled');
				$this->rc->output->command('amacube.page','last','enabled');
			} else {
				// Disable next & last
				$this->rc->output->command('amacube.page','next','disabled');
				$this->rc->output->command('amacube.page','last','disabled');
			}
			// Set output to nothing because client commands were used
			$output = '';
		}
		// Feedback
		$this->feedback();
		return $output;
    }

	function quarantine_display_count() {

		return html::span(array('id' => 'rcmcountdisplay', 'class' => 'countdisplay quarantine-countdisplay'),$this->rc->amacube->pagecount_string);

	}

    function quarantine_post() {

		// Process quarantine
        $delete = array();
        $release = array();
        foreach ($_POST as $key => $value) {
            if (preg_match('/_([dr]el)_([\w\-]+)/', $value, $matches)) {
                if ($matches[1] == 'del') { array_push($delete, $matches[2]); }
                elseif ($matches[1] == 'rel') { array_push($release, $matches[2]); }
            }
        }
		// Intersection error (should no longer happen with radio inputs but still)
        $intersect = array_intersect($delete, $release);
        if (is_array($intersect) && count($intersect) > 0) {
			$this->rc->amacube->errors[] = 'intersection_error';
			$this->rc->output->send('amacube.quarantine');
            return;
        }
		// Process released
		if (!empty($release)) {
			if ($this->amacube->quarantine->release($release)) {
				$this->rc->amacube->feedback[] = array('type' => 'confirmation', 'message' => 'success_release');
			}
		}
		// Process deleted
		if (!empty($delete)) {
			if ($this->amacube->quarantine->delete($delete)) {
				$this->rc->amacube->feedback[] = array('type' => 'confirmation', 'message' => 'success_delete');
			}
		}
    }

	function feedback() {
		// Send first error or feedbacks to client
		if (!empty($this->rc->amacube->errors)) {
			$this->rc->output->command('display_message', rcube_utils::rep_specialchars_output($this->gettext($this->rc->amacube->errors[0]), 'html', 'strict', true), 'error');
		} elseif (!empty($this->rc->amacube->feedback)) {
			foreach ($this->rc->amacube->feedback as $feed) {
				if (!empty($feed)) {
					$this->rc->output->command('display_message', rcube_utils::rep_specialchars_output($this->gettext($feed['message']), 'html', 'strict', true), $feed['type']);
				}
			}
		}

	}

    // CONVENIENCE METHODS
    // This bloody html_checkbox class will always return checkboxes that are "checked"
    // I did not figure out how to prevent that $$*@@!!
    // so I used html::tag instead...
    function _show_checkbox($id, $checked = false)
    {
        $attr_array = array('name' => $id,'id' => $id);
        if($checked) {
            $attr_array['checked'] = 'checked';
        }
        //$box = new html_checkbox($attr_array);
        $attr_array['type'] = 'checkbox';
        $box = html::tag('input',$attr_array);
        return $box;
    }
    function _show_radio($id, $name, $value, $checked = false)
    {
        $attr_array = array('name' => $name,'id' => $id);
        if ($checked) {
            $attr_array['checked'] = 'checked';
        }
        //$box = new html_checkbox($attr_array);
        $attr_array['type'] = 'radio';
		$attr_array['value'] = $value;
        $box = html::tag('input',$attr_array);
        return $box;
    }
    function _show_inputfield($id, $value)
    {
        $input = new html_inputfield(array(
                'name' => $id,
                'id' => $id,
                'value' => $value,
                'size'  =>  10
        ));
        return $input->show();
    }
}
?>
