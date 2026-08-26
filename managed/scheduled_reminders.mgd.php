<?php

use CRM_Events_ExtensionUtil as E;

return [
  [
    'name' => 'SavedSearch_Scheduled_Reminder_Search',
    'entity' => 'SavedSearch',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Scheduled_Reminder_Search_ixiam',
        'label' => E::ts('Scheduled Reminder Search'),
        'api_entity' => 'ActionSchedule',
        'api_params' => [
          'version' => 4,
          'select' => [
            'id',
            'title',
            'mapping_id:label',
            'entity_value:label',
            'entity_status:label',
            'start_action_offset',
            'start_action_unit:label',
            'start_action_condition',
            'start_action_date:label',
            'absolute_date',
            'is_repeat',
            'is_active',
          ],
          'orderBy' => [],
          'where' => [],
          'groupBy' => [],
          'join' => [],
          'having' => [],
        ],
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'SavedSearch_Scheduled_Reminder_Search_SearchDisplay_Table',
    'entity' => 'SearchDisplay',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Scheduled_Reminder_Search_Table',
        'label' => E::ts('Scheduled Reminder Search Table'),
        'saved_search_id.name' => 'Scheduled_Reminder_Search_ixiam',
        'type' => 'table',
        'settings' => [
          'description' => NULL,
          'sort' => [],
          'limit' => 50,
          'pager' => [
            'show_count' => TRUE,
            'expose_limit' => TRUE,
          ],
          'placeholder' => 5,
          'acl_bypass' => FALSE,
          'cssRules' => [
            ['crm-entity-disabled', 'is_active', '=', FALSE],
          ],
          'toolbar' => [
            [
              'entity' => 'ActionSchedule',
              'action' => 'add',
              'target' => 'crm-popup',
              'icon' => 'fa-plus',
              'text' => E::ts('Add Scheduled Reminder'),
              'style' => 'primary',
            ],
          ],
          'columns' => [
            [
              'type' => 'field',
              'key' => 'title',
              'dataType' => 'String',
              'label' => E::ts('Title'),
              'sortable' => TRUE,
              'link' => [
                'path' => '',
                'entity' => 'ActionSchedule',
                'action' => 'update',
                'join' => '',
                'target' => 'crm-popup',
              ],
              'title' => E::ts('Edit'),
              'icons' => [
                ['icon' => 'fa-ban', 'field' => 'is_active', 'if' => ['is_active', '=', FALSE], 'side' => 'left'],
              ],
            ],
            [
              'type' => 'field',
              'key' => 'mapping_id:label',
              'dataType' => 'Integer',
              'label' => E::ts('Reminder For'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'entity_value:label',
              'dataType' => 'String',
              'label' => E::ts('Entity'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'absolute_date',
              'dataType' => 'Date',
              'label' => E::ts('When'),
              'sortable' => TRUE,
              'empty_value' => '[start_action_offset] [start_action_unit:label] [start_action_condition] [start_action_date:label]',
            ],
            [
              'type' => 'field',
              'key' => 'entity_status:label',
              'dataType' => 'String',
              'label' => E::ts('While'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'is_repeat',
              'dataType' => 'Boolean',
              'label' => E::ts('Repeat'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'is_active',
              'dataType' => 'Boolean',
              'label' => E::ts('Active?'),
              'sortable' => TRUE,
            ],
          ],
          'actions' => [
            'update',
            [
              'entity' => 'ActionSchedule',
              'action' => 'update',
              'title' => E::ts('Enable'),
              'icon' => 'fa-toggle-on',
              'style' => 'default',
              'data' => [
                'is_active' => TRUE,
              ],
              'condition' => [
                'is_active',
                '=',
                FALSE,
              ],
            ],
            [
              'entity' => 'ActionSchedule',
              'action' => 'update',
              'title' => E::ts('Disable'),
              'icon' => 'fa-toggle-off',
              'style' => 'default',
              'data' => [
                'is_active' => FALSE,
              ],
              'condition' => [
                'is_active',
                '=',
                TRUE,
              ],
            ],
            'delete',
          ],
          'classes' => [
            'table',
            'table-striped',
          ],
        ],
      ],
      'match' => [
        'saved_search_id.name',
        'name',
      ],
    ],
  ],
];

