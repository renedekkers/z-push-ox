<?php

class OXCalendarSync {

    private $mappingCalendarOXtoASYNC = array(
            'strings' => array(
                    'title' => 'subject',
                    // 'timezone' => 'timezone',
                    'uid' => 'uid',
                    // 'organizer' => 'organizeremail',
                    'location' => 'location',
                    'note' => 'body',
                    'categories' => 'categories',
		    'alarm' => 'reminder',
            ),
            'dates' => array(
                    'start_date' => 'starttime',
                    'end_date' => 'endtime'
            ),
            'booleans' => array(
                    'full_time' => 'alldayevent'
            ),
            'timezone' => array(
                    'timezone' => 'timezone'
            )
    );

    private $mappingCalendarASYNCtoOX = array();
    // will be filled after login
    private $mappingRecurrenceOXtoASYNC = array(
            'strings' => array(
                    'interval' => 'interval',
                    'occurrences' => 'occurrences',
                    'days' => 'dayofweek'
            // 'day_in_month' => 'dayofmonth',
            // 'day_in_month' => 'weekofmonth',
            // 'month' => 'monthofyear', offset of one
                        ),
            'dates' => array(
                    'until' => 'until'
            )
    );

    private $mappingRecurrenceASYNCtoOX = array();
    // will be filled after login
    public function OXCalendarSync ($OXConnector, $OXUtils) {
        $this->OXConnector = $OXConnector;
        $this->OXUtils = $OXUtils;
        $this->mappingRecurrenceASYNCtoOX = $this->OXUtils->reversemap(
                $this->mappingRecurrenceOXtoASYNC);
        $this->mappingCalendarASYNCtoOX = $this->OXUtils->reversemap(
                $this->mappingCalendarOXtoASYNC);
        ZLog::Write(LOGLEVEL_DEBUG, 'OXCalendarSync initialized.');
    }

    public function GetMessageList ($folder, $cutoffdate) {
        $folderid = $folder->serverid;
        
        $response = $this->OXConnector->OXreqGET('/ajax/calendar', 
                array(
                        'action' => 'all',
                        'session' => $this->OXConnector->getSession(),
                        'folder' => $folderid,
                        'columns' => '1,5,', // objectID | last modified
                        'start' => '0',
                        'end' => '2208988800000',
                        'recurrence_master' => 'true'
                ));
        ZLog::Write(LOGLEVEL_DEBUG, 
                'OXCalendarSync::GetMessageList(folderid: ' . $folderid .
                         '  folder: ' . $folder->displayname . '  data: ' .
                         json_encode($response) . ')');
        $message = array();
        foreach ($response["data"] as &$event) {
            $message["id"] = $event[0];
            $message["mod"] = $event[1];
            $message["flags"] = 1;
            // always 'read'
            $messages[] = $message;
        }
        return $messages;
    }

    private function _GetEvent ($folderid, $id) {
        $response = $this->OXConnector->OXreqGET('/ajax/calendar', 
                array(
                        'action' => 'get',
                        'session' => $this->OXConnector->getSession(),
                        'id' => $id,
                        'folder' => $folderid,
                        'timezone' => 'UTC', // causes the ox server to return
                                             // all dates in utc
                        'recurrence_master' => 'true'
                ));
        return $response;
    }

    public function GetMessage ($folder, $id, $contentparameters) {
        $folderid = $folder->serverid;
        
        ZLog::Write(LOGLEVEL_DEBUG, 
                'OXCalendarSync::GetMessage(' . $folderid . ', ' . $id .
                         ', folder: ' . json_encode($folder) . ' ...)');
        
        $response = $this->_GetEvent($folderid, $id);
        $data = $response["data"];
        ZLog::Write(LOGLEVEL_DEBUG, 
                'OXCalendarSync::GetMessage(appointment data: ' .
                         json_encode($data) . ')');
        
        $event = $this->ox2appointment($data);
        
        // handle recurrence information
        $event->recurrence = $this->recurrenceOX2Async($data);
        
        // check exceptions of recurrence information
        $response = $this->OXConnector->OXreqGET('/ajax/calendar', 
                array(
                        'action' => 'all',
                        'session' => $this->OXConnector->getSession(),
                        'folder' => $folderid,
                        'columns' => '1,206', // id, recurrence_id
                        'start' => '0',
                        'end' => '2208988800000',
                        'recurrence_master' => 'true'
                ));
        foreach ($response["data"] as &$_event) {
            $event_id = $_event[0];
            $event_recurrence_id = $_event[1];
            if ($event_recurrence_id == $id and $event_recurrence_id != $event_id) {
                // found an event that is an exception for event $id
                $eresponse = $this->_GetEvent($folderid, $event_id);
                $eevent = $this->ox2appointment($eresponse["data"], True);
                $eevent->exceptionstarttime = $this->OXUtils->timestampOXtoPHP(
                        $eresponse["data"]["change_exceptions"][0]);
                $event->exceptions[] = $eevent;
            }
        }
        
        // check for delete exceptions (deleted events in a recurrence)
        if (array_key_exists("delete_exceptions", $data)) { // check if delete
                                                           // exceptions exist
            foreach ($data["delete_exceptions"] as &$delete_exception) {
                $appointmentexception = new SyncAppointmentException();
                $appointmentexception->deleted = 1;
                $appointmentexception->exceptionstarttime = $this->OXUtils->timestampOXtoPHP(
                        $delete_exception);
                if ($event->alldayevent) {
                    $appointmentexception->exceptionstarttime = $this->OXUtils->convert_time_zone(
                            $appointmentexception->exceptionstarttime, "UTC", 
                            $ $event->oxtimezone);
                }
                $event->exceptions[] = $appointmentexception;
            }
        }
        
        ZLog::Write(LOGLEVEL_DEBUG, 
                'OXCalendarSync::GetMessage(' . $folderid . ', ' . $id .
                         ', event: ' . json_encode($event) . ')');
        return $event;
    }

    public function StatMessage ($folder, $id) {
        $folderid = $folder->serverid;
        
        // Default values:
        $message = array();
        $message["id"] = $id;
        $message["flags"] = 1;
        
        ZLog::Write(LOGLEVEL_DEBUG, 
                'OXCalendarSync::StatMessage(' . $folderid . ', ' . $id .
                         ', ...)');
        
        $response = $this->OXConnector->OXreqGET('/ajax/calendar', 
                array(
                        'action' => 'get',
                        'session' => $this->OXConnector->getSession(),
                        'id' => $id,
                        'folder' => $folderid,
                        'recurrence_master' => 'true'
                ));
        $message["mod"] = $response["data"]["last_modified"];
        return $message;
    }

    /**
     * Called when a message has been changed on the mobile.
     * This functionality is not available for emails.
     *
     * @param string $folderid
     *            id of the folder
     * @param string $id
     *            id of the message | if id not set create the message
     * @param SyncXXX $message
     *            the SyncObject containing a message
     *            
     * @access public
     * @return array same return value as StatMessage()
     * @throws StatusException could throw specific SYNC_STATUS_* exceptions
     */
    public function ChangeMessage ($folder, $id, $message, $contentParameters) {
        $folderid = $folder->serverid;
        
        ZLog::Write(LOGLEVEL_DEBUG, 
                'OXCalendarSync::ChangeMessage(' . $folderid . ', ' . $id .
                         ', ...)');
        
        if (! $id) {
            // id is not set => create object
            $createResponse = $this->OXConnector->OXreqPUT('/ajax/calendar', 
                    array(
                            'action' => 'new',
                            'session' => $this->OXConnector->getSession()
                    ), 
                    array(
                            'folder_id' => $folderid, // set the folder in which
                                                      // the user should be
                                                      // created
                            'start_date' => 0,
                            'end_date' => 0
                    ));
            
            if (! $createResponse) {
                ZLog::Write(LOGLEVEL_DEBUG, 
                        'OXCalendarSync::ChangeMessage(failed to create object in folder: ' .
                                 $folder->displayname . ')');
                throw new StatusException(
                        'failed to create new object in folder: ' .
                                 $folder->displayname, 
                                SYNC_STATUS_SYNCCANNOTBECOMPLETED);
                return false;
            }
            
            $id = $createResponse["data"]["id"];
        }
        
        $diffOX = $this->appointment2ox($folder, $id, $message);
        
        // append recurrence data
        $diffOX = array_merge($diffOX, 
                $this->recurrenceAsync2OX($message->recurrence));
        ZLog::Write(LOGLEVEL_DEBUG, "DiffData: " . json_encode($diffOX));
        $stat = $this->StatMessage($folder, $id);
        $response = $this->OXConnector->OXreqPUT('/ajax/calendar', 
                array(
                        'action' => 'update',
                        'session' => $this->OXConnector->getSession(),
                        'folder' => $folderid,
                        'id' => $id,
                        'timestamp' => $stat["mod"]
                ), $diffOX);
        
        if ($response) {
            ZLog::Write(LOGLEVEL_DEBUG, 
                    'OXCalendarSync::ChangeMessage(successfully changed - folder: ' .
                             $folder->displayname . '   id: ' . $id . ')');
            return $this->StatMessage($folder, $id);
        } else {
            throw new StatusException(
                    'could not change contact: ' . $id . ' in folder: ' .
                             $folder->displayname, 
                            SYNC_STATUS_SYNCCANNOTBECOMPLETED);
            return false;
        }
    }

    /**
     * Called when the user has requested to delete (really delete) a message
     *
     * @param string $folderid
     *            id of the folder
     * @param string $id
     *            id of the message
     *            
     * @access public
     * @return boolean status of the operation
     * @throws StatusException could throw specific SYNC_STATUS_* exceptions
     */
    public function DeleteMessage ($folder, $id, $contentParameters) {
        ZLog::Write(LOGLEVEL_DEBUG, 
                'OXContactSync::DeleteMessage(' . $folder->serverid . ', ' . $id .
                         ')');
        
        $stat = $this->StatMessage($folder, $id);
        $response = $this->OXConnector->OXreqPUT('/ajax/calendar', 
                array(
                        'action' => 'delete',
                        'session' => $this->OXConnector->getSession(),
                        'timestamp' => $stat["mod"]
                ), 
                array(
                        'folder' => $folder->serverid,
                        'id' => $id
                ));
        if ($response) {
            return true;
        }
        return false;
    }

    /**
     * Called when the user moves an item on the PDA from one folder to another
     * not implemented
     *
     * @param string $folderid
     *            id of the source folder
     * @param string $id
     *            id of the message
     * @param string $newfolderid
     *            id of the destination folder
     *            
     * @access public
     * @return boolean status of the operation
     * @throws StatusException could throw specific SYNC_MOVEITEMSSTATUS_*
     *         exceptions
     */
    public function MoveMessage ($folder, $id, $newfolderid, $contentParameters) {
        $folderid = $folder->serverid;
        ZLog::Write(LOGLEVEL_DEBUG, 
                'OXCalendarSync::MoveMessage(' . $folderid . ', ' . $id . ', ' .
                         $newfolderid . ')');
    }

    /**
     * Changes the 'read' flag of a message on disk
     *
     * @param string $folder
     *            id of the folder
     * @param string $id
     *            id of the message
     * @param int $flags
     *            read flag of the message
     *            
     * @access public
     * @return boolean status of the operation
     * @throws StatusException could throw specific SYNC_STATUS_* exceptions
     */
    public function SetReadFlag ($folder, $id, $flags, $contentParameters) {
        ZLog::Write(LOGLEVEL_DEBUG, 
                'OXCalendarSync::SetReadFlag(' . $folderid . ', ' . $id . ', ' .
                         $flags . ')');
    }

    /**
     * Deletes a folder
     *
     * @param folder $folder            
     * @param string $parent
     *            is normally false
     *            
     * @access public
     * @return boolean status - false if e.g. does not exist
     * @throws StatusException could throw specific SYNC_FSSTATUS_* exceptions
     *        
     */
    public function DeleteFolder ($folder, $parentid) {
        $id = $folder->serverid;
        ZLog::Write(LOGLEVEL_DEBUG, 
                'OXCalendarSync::DeleteFolder(' . $id . ',' . $parentid . ')');
        
        return true;
    }

    /**
     * returns a syncappointment based on $data
     *
     * @param array $data            
     */
    private function ox2appointment ($data, $exception = False) {
        if (! $exception) {
            $appointment = new SyncAppointment();
        } else {
            $appointment = new SyncAppointmentException();
        }
        $appointment = $this->OXUtils->mapValues($data, $appointment, 
                $this->mappingCalendarOXtoASYNC, 'php', 
                array(
                        'allday' => $data["full_time"]
                ));
        
        $appointment->oxtimezone = $data["timezone"];
        $appointment->meetingstatus = "1"; // see issue #4
        
        return $appointment;
    }

    /**
     * returns a data object based on a syncappointment
     *
     * @param string $folderid
     *            id of the folder
     * @param string $id
     *            id of the message | if id not set create the message
     * @param SyncAppointment $appointment            
     */
    private function appointment2ox ($folder, $id, $appointment) {
        $oldmessage = $this->GetMessage($folder, $id, null);
        $appointment->oxtimezone = $this->OXUtils->getTimezone(
                $appointment->timezone, $oldmessage->oxtimezone);
        
        $diff = $this->OXUtils->diffSyncObjects($appointment, $oldmessage);
        $diff['timezone'] = $appointment->timezone;
        
        $diffOX = $this->OXUtils->mapValues($diff, array(), 
                $this->mappingCalendarASYNCtoOX, 'ox', 
                array(
                        'allday' => $appointment->alldayevent,
                        'expectedTimezone' => $oldmessage->oxtimezone
                ));
        
        return $diffOX;
    }

    private function recurrenceOX2Async ($data) {
        ZLog::Write(LOGLEVEL_DEBUG, 
                'BackendOX::recurrenceOX2Async(' . json_encode($data) . ')');
        $recurrence = new SyncRecurrence();
        switch ($data["recurrence_type"]) {
            case 0:
                // no recurrence
                $recurrence = null;
                break;
            
            case 1:
                // daily
                $recurrence->type = 0;
                $this->OXUtils->mapValues($data, $recurrence, 
                        $this->mappingRecurrenceOXtoASYNC, 'php');
                break;
            
            case 2:
                // weekly
                $recurrence->type = 1;
                $this->OXUtils->mapValues($data, $recurrence, 
                        $this->mappingRecurrenceOXtoASYNC, 'php');
                $recurrence->dayofmonth = $data["day_in_month"];
                break;
            
            case 3:
                // monthly | monthly on the nth day
                $this->OXUtils->mapValues($data, $recurrence, 
                        $this->mappingRecurrenceOXtoASYNC, 'php');
                if ($recurrence->dayofweek) {
                    // monthly on the nth day
                    $recurrence->type = 3;
                    $recurrence->weekofmonth = $data["day_in_month"];
                } else {
                    // monthly
                    $recurrence->type = 2;
                    $recurrence->dayofmonth = $data["day_in_month"];
                }
                break;
            
            case 4:
                // yearly
                $this->OXUtils->mapValues($data, $recurrence, 
                        $this->mappingRecurrenceOXtoASYNC, 'php');
                $recurrence->monthofyear = intval($data["month"]) + 1;
                if ($recurrence->dayofweek) {
                    // yearly
                    $recurrence->type = 6;
                    $recurrence->weekofmonth = $data["day_in_month"];
                } else {
                    // yearly on the nth day
                    $recurrence->type = 5;
                    $recurrence->dayofmonth = $data["day_in_month"];
                }
                break;
        }
        return $recurrence;
    }

    private function recurrenceAsync2OX ($data) {
        ZLog::Write(LOGLEVEL_DEBUG, 
                'BackendOX::recurrenceAsync2OX(' . json_encode($data) . ')');
        
        $recurrence = array();
        
        if (! $data) {
            $recurrence['recurrence_type'] = "0";
            return $recurrence;
        }
        
        switch ($data->type) {
            
            case 0:
                // daily
                $recurrence["recurrence_type"] = 1;
                $recurrence = array_merge(
                        $this->OXUtils->mapValues($data, $recurrence, 
                                $this->mappingRecurrenceASYNCtoOX, 'ox'), 
                        $recurrence);
                unset($recurrence['days']);
                break;
            
            case 1:
                // weekly
                $recurrence["recurrence_type"] = 2;
                $recurrence = array_merge(
                        $this->OXUtils->mapValues($data, $recurrence, 
                                $this->mappingRecurrenceASYNCtoOX, 'ox'), 
                        $recurrence);
                break;
            
            case 2:
                // monthly
                $recurrence["recurrence_type"] = 3;
                $recurrence = array_merge(
                        $this->OXUtils->mapValues($data, $recurrence, 
                                $this->mappingRecurrenceASYNCtoOX, 'ox'), 
                        $recurrence);
                $recurrence["day_in_month"] = $data->dayofmonth;
                unset($recurrence['days']);
                break;
            
            case 3:
                // monthly on the nth day
                $recurrence["recurrence_type"] = 3;
                $recurrence = array_merge(
                        $this->OXUtils->mapValues($data, $recurrence, 
                                $this->mappingRecurrenceASYNCtoOX, 'ox'), 
                        $recurrence);
                $recurrence["day_in_month"] = $data->weekofmonth;
                break;
            
            case 5:
                // yearly
                $recurrence["recurrence_type"] = 4;
                $recurrence = array_merge(
                        $this->OXUtils->mapValues($data, $recurrence, 
                                $this->mappingRecurrenceASYNCtoOX, 'ox'), 
                        $recurrence);
                $recurrence["month"] = intval($data->monthofyear) - 1;
                $recurrence["day_in_month"] = $data->dayofmonth;
                break;
            
            case 6:
                // yearly on the nth day
                $recurrence["recurrence_type"] = 4;
                $recurrence = array_merge(
                        $this->OXUtils->mapValues($data, $recurrence, 
                                $this->mappingRecurrenceASYNCtoOX, 'ox'), 
                        $recurrence);
                $recurrence["month"] = intval($data->monthofyear) - 1;
                $recurrence["day_in_month"] = $data->weekofmonth;
                break;
        }
        
        if (array_key_exists('occurrences', $recurrence) &&
                 $recurrence['occurrences'] == null) {
            unset($recurrence['occurrences']);
        }
        
        if ($recurrence["recurrence_type"] > 2 &&
                 array_key_exists('days', $recurrence) &&
                 $recurrence['days'] == null) {
            $recurrence['days'] = 127;
        }
        
        return $recurrence;
    }
}
?>
