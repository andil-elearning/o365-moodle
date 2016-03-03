<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * @package local_o365
 * @author James McQuillan <james.mcquillan@remote-learner.net>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright (C) 2014 onwards Microsoft Open Technologies, Inc. (http://msopentech.com/)
 */

namespace local_o365\task;

/**
 * Scheduled task to process a batch from the match queue.
 */
class processmatchqueue extends \core\task\scheduled_task {
    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_processmatchqueue', 'local_o365');
    }

    /**
     * Construct an API client.
     *
     * @return \local_o365\rest\o365api|bool A constructed user API client (unified or legacy), or false if error.
     */
    public function get_api() {
        $unifiedconfigured = \local_o365\rest\unified::is_configured();
        if ($unifiedconfigured === true) {
            $resource = \local_o365\rest\unified::get_resource();
        } else {
            $resource = \local_o365\rest\azuread::get_resource();
        }

        $clientdata = \local_o365\oauth2\clientdata::instance_from_oidc();
        $httpclient = new \local_o365\httpclient();
        $token = \local_o365\oauth2\systemtoken::instance(null, $resource, $clientdata, $httpclient);
        if (empty($token)) {
            throw new \Exception('No token available for system user. Please run local_o365 health check.');
        }

        if ($unifiedconfigured === true) {
            $apiclient = new \local_o365\rest\unified($token, $httpclient);
        } else {
            $apiclient = new \local_o365\rest\azuread($token, $httpclient);
        }
        return $apiclient;
    }

    /**
     * Do the job.
     */
    public function execute() {
        global $DB;

        if (\local_o365\utils::is_configured() !== true) {
            return false;
        }

        $sql = 'SELECT mq.*,
                       u.id as muserid,
                       muserconn.id as muserexistingconnectionid,
                       officeconn.id as officeuserexistingconnectionid,
                       oidctok.id as officeuserexistingoidctoken
                  FROM {local_o365_matchqueue} mq
             LEFT JOIN {user} u ON mq.musername = u.username
             LEFT JOIN {local_o365_connections} muserconn ON muserconn.muserid = u.id
             LEFT JOIN {local_o365_connections} officeconn ON officeconn.aadupn = mq.o365username
             LEFT JOIN {auth_oidc_token} oidctok ON oidctok.oidcusername = mq.o365username
                 WHERE mq.completed = ? AND mq.errormessage = ?
              ORDER BY mq.id ASC';
        $params = ['0', ''];
        $matchqueue = $DB->get_recordset_sql($sql, $params, 0, 100);
        $apiclient = $this->get_api();
        foreach ($matchqueue as $matchrec) {
            mtrace('Processing '.$matchrec->musername.'/'.$matchrec->o365username);
            try {
                // Check for matching Moodle user.
                if (empty($matchrec->muserid)) {
                    $updatedrec = new \stdClass;
                    $updatedrec->id = $matchrec->id;
                    $updatedrec->errormessage = get_string('task_processmatchqueue_err_nomuser', 'local_o365');
                    $updatedrec->completed = 1;
                    $DB->update_record('local_o365_matchqueue', $updatedrec);
                    mtrace($updatedrec->errormessage);
                    continue;
                }

                // Check whether Moodle user is already o365 connected.
                if (\local_o365\utils::is_o365_connected($matchrec->muserid)) {
                    $updatedrec = new \stdClass;
                    $updatedrec->id = $matchrec->id;
                    $updatedrec->errormessage = get_string('task_processmatchqueue_err_museralreadyo365', 'local_o365');
                    $updatedrec->completed = 1;
                    $DB->update_record('local_o365_matchqueue', $updatedrec);
                    mtrace($updatedrec->errormessage);
                    continue;
                }

                // Check existing matches for Moodle user.
                if (!empty($matchrec->muserexistingconnectionid)) {
                    $updatedrec = new \stdClass;
                    $updatedrec->id = $matchrec->id;
                    $updatedrec->errormessage = get_string('task_processmatchqueue_err_museralreadymatched', 'local_o365');
                    $updatedrec->completed = 1;
                    $DB->update_record('local_o365_matchqueue', $updatedrec);
                    mtrace($updatedrec->errormessage);
                    continue;
                }

                // Check existing matches for Office user.
                if (!empty($matchrec->officeuserexistingconnectionid)) {
                    $updatedrec = new \stdClass;
                    $updatedrec->id = $matchrec->id;
                    $updatedrec->errormessage = get_string('task_processmatchqueue_err_o365useralreadymatched', 'local_o365');
                    $updatedrec->completed = 1;
                    $DB->update_record('local_o365_matchqueue', $updatedrec);
                    mtrace($updatedrec->errormessage);
                    continue;
                }

                // Check existing tokens for Office 365 user (indicates o365 user is already connected to someone).
                if (!empty($matchrec->officeuserexistingoidctoken)) {
                    $updatedrec = new \stdClass;
                    $updatedrec->id = $matchrec->id;
                    $updatedrec->errormessage = get_string('task_processmatchqueue_err_o365useralreadyconnected', 'local_o365');
                    $updatedrec->completed = 1;
                    $DB->update_record('local_o365_matchqueue', $updatedrec);
                    mtrace($updatedrec->errormessage);
                    continue;
                }

                // Check o365 username.
                $userfound = false;
                try {
                    $o365user = $apiclient->get_user_by_upn($matchrec->o365username);
                    $userfound = true;
                } catch (\Exception $e) {
                    $userfound = false;
                }

                if ($userfound !== true) {
                    $updatedrec = new \stdClass;
                    $updatedrec->id = $matchrec->id;
                    $updatedrec->errormessage = get_string('task_processmatchqueue_err_noo365user', 'local_o365');
                    $updatedrec->completed = 1;
                    $DB->update_record('local_o365_matchqueue', $updatedrec);
                    mtrace($updatedrec->errormessage);
                    continue;
                }

                // Match validated.
                $connectionrec = new \stdClass;
                $connectionrec->muserid = $matchrec->muserid;
                $connectionrec->aadupn = \core_text::strtolower($o365user['userPrincipalName']);
                $connectionrec->uselogin = 0;
                $DB->insert_record('local_o365_connections', $connectionrec);
                $updatedrec = new \stdClass;
                $updatedrec->id = $matchrec->id;
                $updatedrec->completed = 1;
                $DB->update_record('local_o365_matchqueue', $updatedrec);
                mtrace('Match record created for userid #'.$matchrec->muserid.' and o365 user '.$connectionrec->aadupn);

            } catch (\Exception $e) {
                $exceptionstring = $e->getMessage().': '.$e->debuginfo;
                $updatedrec = new \stdClass;
                $updatedrec->id = $matchrec->id;
                $updatedrec->errormessage = $exceptionstring;
                $updatedrec->completed = 1;
                $DB->update_record('local_o365_matchqueue', $updatedrec);
                mtrace($exceptionstring);
            }
        }
        $matchqueue->close();

        return true;
    }
}