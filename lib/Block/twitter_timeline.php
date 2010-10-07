<?php
/**
 * The Horde_Block_twitter_timeline class provides a bare-bones twitter client
 * as a horde block.
 *
 * Still @TODO:
 *  - configure block to show friendTimeline, specific user, public timeline,
 *    'mentions' for current user etc..
 *  - keep track of call limits and either dynamically alter update time or
 *    at least provide feedback to user.
 *
 * Copyright 2009-2010 The Horde Project (http://www.horde.org)
 *
 * See the enclosed file COPYING for license information (LGPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/lgpl.html.
 *
 * @author Ben Klang <ben@alkaloid.net>
 * @author Michael J. Rubinsky <mrubinsk@horde.org>
 *
 * @package Horde_Block
 */
if (!empty($GLOBALS['conf']['twitter']['enabled'])) {
    $block_name = _("Twitter Timeline");
}

class Horde_Block_Horde_twitter_timeline extends Horde_Block
{
    /**
     * Whether this block has changing content. Set this to false since we
     * handle the updates via AJAX on our own.
     *
     */
    public $updateable = false;

    /**
     *
     * @ Horde_Service_Twitter
     */
    private $_twitter;

    /**
     * Twitter profile information returned from verify_credentials
     *
     * @var Object
     */
    private $_profile;

    /**
     *
     * @var string
     */
    protected $_app = 'horde';

    /**
     * The title to go in this block.
     *
     * @return string   The title text.
     */
    protected function _title()
    {
        try {
            $twitter = $this->_getTwitterObject();
        } catch (Horde_Exception $e) {
            return _("Twitter Timeline");
        }
        try {
            $this->_profile = Horde_Serialize::unserialize($twitter->account->verifyCredentials(), Horde_Serialize::JSON);
            if (!empty($this->_profile)) {
                $username = $this->_profile->screen_name;
                return sprintf(_("Twitter Timeline for %s"), $username);
            }
        } catch (Horde_Service_Twitter_Exception $e) {
            if (empty($this->_params['username'])) {
                return _("Twitter Timeline");
            }
        }

        return sprintf(_("Twitter Timeline"));
    }

    /**
     */
    protected function _params()
    {
        return array(
            'height' => array(
                 'name' => _("Height of map (width automatically adjusts to block)"),
                 'type' => 'int',
                 'default' => 350),
            'refresh_rate' => array(
                 'name' => _("Number of seconds to wait to refresh"),
                 'type' => 'int',
                 'default' => 300)
        );
    }

    /**
     * The content to go in this block.
     *
     * @return string   The content
     */
    protected function _content()
    {
        global $conf;

        /* Get the twitter driver */
        try {
            $twitter = $this->_getTwitterObject();
        }  catch (Horde_Exception $e) {
            throw new Horde_Block_Exception(sprintf(_("There was an error contacting Twitter: %s"), $e->getMessage()));
        }

        /* Get a unique ID in case we have multiple Twitter blocks. */
        $instance = (string)new Horde_Support_Randomid();

        /* Latest status */
        if (empty($this->_profile->status)) {
            // status might not be set if only updating the block via ajax
            try {
              $this->_profile = Horde_Serialize::unserialize($twitter->account->verifyCredentials(), Horde_Serialize::JSON);
              if (empty($this->_profile)) {
                  return _("Temporarily unable to contact Twitter. Please try again later.");
              }
            } catch (Horde_Service_Twitter_Exception $e) {
                $msg = Horde_Serialize::unserialize($e->getMessage(), Horde_Serialize::JSON);
                return sprintf(_("There was an error contacting Twitter: %s"), $msg);
            }
        }

        /* Build values to pass to the javascript twitter client */
        $defaultText = _("What are you working on now?");
        $endpoint = Horde::url('services/twitter.php', true);
        $spinner = $instance . '_loading';
        $inputNode = $instance . '_newStatus';
        $inReplyToNode = $instance . '_inReplyTo';
        $inReplyToText = _("In reply to:");
        $contentNode = 'twitter_body' . $instance;
        $justNowText = _("Just now...");
        $refresh = empty($this->_params['refresh_rate']) ? 300 : $this->_params['refresh_rate'];

        /* Add the client javascript / initialize it */
        Horde::addScriptFile('twitterclient.js');
        $script = <<<EOT
            var Horde = window.Horde || {};
            Horde['twitter{$instance}'] = new Horde_Twitter({
               instanceid: '{$instance}',
               getmore: '{$instance}_getmore',
               input: '{$instance}_newStatus',
               spinner: '{$instance}_loading',
               content: '{$instance}_twitter_body',
               endpoint: '{$endpoint}',
               inreplyto: '{$inReplyToNode}',
               refreshrate: {$refresh},
               counter: '{$instance}_counter',
               strings: { inreplyto: '{$inReplyToText}', defaultText: '{$defaultText}', justnow: '{$justNowText}' }
            });
EOT;
        Horde::addInlineScript($script, 'dom');

        /* Get the user's most recent tweet */
        $latestStatus = htmlspecialchars($this->_profile->status->text);

        /* Build the UI */
        $html = '<div style="padding: 0 8px 8px">'
           . '<div class="fbgreybox"><textarea rows="2" style="width:98%;margin-top:4px;margin-bottom:4px;" type="text" id="' . $instance . '_newStatus" name="' . $instance . '_newStatus">' . $defaultText . '</textarea>'
           . '<a class="button" onclick="Horde[\'twitter' . $instance . '\'].updateStatus($F(\'' . $instance . '_newStatus\'));" href="#">' . _("Tweet") . '</a><span id="' . $instance . '_counter" style="color: rgb(204, 204, 204);margin-left:6px;">140</span>  <span id="' . $instance . '_inReplyTo"></span>'
           . Horde::img('loading.gif', '', array('id' => $instance . '_loading', 'style' => 'display:none;'));
        $html .= '<div id="currentStatus" class="" style="margin: 10px;"><strong>' . _("Latest") . '</strong> ' . $latestStatus . ' - <span class="fbstreaminfo">' . Horde_Date_Utils::relativeDateTime(strtotime($this->_profile->status->created_at), $GLOBALS['prefs']->getValue('date_format'), ($GLOBALS['prefs']->getValue('twentyFour') ? "%H:%M" : "%I:%M %P")) . '</span></div></div>';
        $html .= '<div style="height:' . (empty($this->_params['height']) ? 350 : $this->_params['height']) . 'px;overflow-y:auto;" id="' . $instance . '_twitter_body">';
        $html .= '</div>';
        $html .= '<div class="hordeSmGetmore"><input type="button" class="button" id="' . $instance . '_getmore" value="' . _("Get More") . '"></div>';
        $html .= '</div>';

        return $html;
    }

    private function _getTwitterObject()
    {
        $token = unserialize($GLOBALS['prefs']->getValue('twitter'));
        if (empty($token['key']) && empty($token['secret'])) {
            $pref_link = Horde::getServiceLink('prefs', 'horde')->add('group', 'twitter')->link();
            throw new Horde_Exception(sprintf(_("You have not properly connected your Twitter account with Horde. You should check your Twitter settings in your %s."), $pref_link . _("preferences") . '</a>'));
        }

        $this->_twitter = $GLOBALS['injector']->getInstance('Horde_Service_Twitter');
        $auth_token = new Horde_Oauth_Token($token['key'], $token['secret']);
        $this->_twitter->auth->setToken($auth_token);

        return $this->_twitter;
    }

}
