<?php

/**
 * ---------------------------------------------------------------------
 *
 * GLPI - Gestionnaire Libre de Parc Informatique
 *
 * http://glpi-project.org
 *
 * @copyright 2015-2025 Teclib' and contributors.
 * @licence   https://www.gnu.org/licenses/gpl-3.0.html
 *
 * ---------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of GLPI.
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
 *
 * ---------------------------------------------------------------------
 */

namespace Glpi\Form\Migration;

use DBmysql;
use Glpi\DBAL\JsonFieldInterface;
use Glpi\DBAL\QueryExpression;
use Glpi\DBAL\QueryUnion;
use Glpi\Form\AccessControl\ControlType\AllowList;
use Glpi\Form\AccessControl\ControlType\AllowListConfig;
use Glpi\Form\AccessControl\ControlType\DirectAccess;
use Glpi\Form\AccessControl\ControlType\DirectAccessConfig;
use Glpi\Form\AccessControl\FormAccessControl;
use Glpi\Form\AccessControl\FormAccessControlManager;
use Glpi\Form\Category;
use Glpi\Form\Comment;
use Glpi\Form\Destination\AbstractConfigField;
use Glpi\Form\Destination\AbstractCommonITILFormDestination;
use Glpi\Form\Destination\CommonITILField\ContentField;
use Glpi\Form\Destination\CommonITILField\ITILActorField;
use Glpi\Form\Destination\FormDestination;
use Glpi\Form\Destination\FormDestinationChange;
use Glpi\Form\Destination\FormDestinationProblem;
use Glpi\Form\Destination\FormDestinationTicket;
use Glpi\Form\Form;
use Glpi\Form\FormTranslation;
use Glpi\Form\Question;
use Glpi\Form\QuestionType\QuestionTypeCheckbox;
use Glpi\Form\QuestionType\QuestionTypeDateTime;
use Glpi\Form\QuestionType\QuestionTypeDropdown;
use Glpi\Form\QuestionType\QuestionTypeEmail;
use Glpi\Form\QuestionType\QuestionTypeFile;
use Glpi\Form\QuestionType\QuestionTypeItem;
use Glpi\Form\QuestionType\QuestionTypeItemDropdown;
use Glpi\Form\QuestionType\QuestionTypeLongText;
use Glpi\Form\QuestionType\QuestionTypeNumber;
use Glpi\Form\QuestionType\QuestionTypeRadio;
use Glpi\Form\QuestionType\QuestionTypeRequester;
use Glpi\Form\QuestionType\QuestionTypeRequestType;
use Glpi\Form\QuestionType\QuestionTypeShortText;
use Glpi\Form\QuestionType\QuestionTypeUrgency;
use Glpi\Form\Section;
use Glpi\Message\MessageType;
use Glpi\Migration\AbstractPluginMigration;
use LogicException;

class FormMigration extends AbstractPluginMigration
{
    private FormAccessControlManager $formAccessControlManager;

    /**
     * Store created forms indexed by plugin form ID for quick access
     * @var array<int, Form>
     */
    private array $forms = [];

    public function __construct(
        DBmysql $db,
        FormAccessControlManager $formAccessControlManager
    ) {
        parent::__construct($db);

        $this->formAccessControlManager = $formAccessControlManager;
    }

    /**
     * Retrieve the map of types to convert
     *
     * @return array
     */
    public function getTypesConvertMap(): array
    {
        return [
            // TODO: We do not have a question of type "Actor",
            // we have more specific types: "Assignee", "Requester" and "Observer"
            'actor'       => QuestionTypeRequester::class,

            'checkboxes'  => QuestionTypeCheckbox::class,
            'date'        => QuestionTypeDateTime::class,
            'datetime'    => QuestionTypeDateTime::class,
            'dropdown'    => QuestionTypeItemDropdown::class,
            'email'       => QuestionTypeEmail::class,
            'file'        => QuestionTypeFile::class,
            'float'       => QuestionTypeNumber::class,
            'glpiselect'  => QuestionTypeItem::class,
            'integer'     => QuestionTypeNumber::class,
            'multiselect' => QuestionTypeDropdown::class,
            'radios'      => QuestionTypeRadio::class,
            'requesttype' => QuestionTypeRequestType::class,
            'select'      => QuestionTypeDropdown::class,
            'textarea'    => QuestionTypeLongText::class,
            'text'        => QuestionTypeShortText::class,
            'time'        => QuestionTypeDateTime::class,
            'urgency'     => QuestionTypeUrgency::class,

            // Description is replaced by a new block : Comment
            'description' => null,

            // TODO: Must be implemented
            'fields'      => null,
            'tag'         => null,

            // TODO: This types are not supported by the new form system
            // we need to define alternative ways to handle them
            'hidden'      => null,
            'hostname'    => null,
            'ip'          => null,
            'ldapselect'  => null,
            'undefined'   => null,
        ];
    }

    private const PUBLIC_ACCESS_TYPE = 0;
    private const PRIVATE_ACCESS_TYPE = 1;
    private const RESTRICTED_ACCESS_TYPE = 2;

    /**
     * Get the class strategy to use based on the access type
     *
     * @return array Mapping between access type constants and strategy classes
     */
    private function getStrategyForAccessTypes(): array
    {
        return [
            self::PUBLIC_ACCESS_TYPE => DirectAccess::class,
            self::PRIVATE_ACCESS_TYPE => DirectAccess::class,
            self::RESTRICTED_ACCESS_TYPE => AllowList::class
        ];
    }

    /**
     * Create the appropriate strategy configuration based on form access rights
     *
     * @param array $form_access_rights The access rights data from the database
     * @return JsonFieldInterface The configuration object for the access control strategy
     * @throws LogicException When no strategy config is found for the given access type
     */
    private function getStrategyConfigForAccessTypes(array $form_access_rights): JsonFieldInterface
    {
        $clean_ids = static fn(array $ids) => array_unique(array_filter($ids, fn(mixed $id) => is_int($id)));

        if (in_array($form_access_rights['access_rights'], [self::PUBLIC_ACCESS_TYPE, self::PRIVATE_ACCESS_TYPE])) {
            return new DirectAccessConfig(
                allow_unauthenticated: $form_access_rights['access_rights'] === self::PUBLIC_ACCESS_TYPE
            );
        } elseif ($form_access_rights['access_rights'] === self::RESTRICTED_ACCESS_TYPE) {
            return new AllowListConfig(
                user_ids: $clean_ids(json_decode($form_access_rights['user_ids'], associative: true, flags: JSON_THROW_ON_ERROR)),
                group_ids: $clean_ids(json_decode($form_access_rights['group_ids'], associative: true, flags: JSON_THROW_ON_ERROR)),
                profile_ids: $clean_ids(json_decode($form_access_rights['profile_ids'], associative: true, flags: JSON_THROW_ON_ERROR))
            );
        }

        throw new LogicException("Strategy config not found for access type {$form_access_rights['access_rights']}");
    }

    protected function validatePrerequisites(): bool
    {
        $formcreator_schema = [
            'glpi_plugin_formcreator_categories' => [
                'id', 'name', 'plugin_formcreator_categories_id', 'level'
            ],
            'glpi_plugin_formcreator_forms' => [
                'id', 'name', 'description', 'plugin_formcreator_categories_id', 'entities_id',
                'is_recursive', 'is_visible'
            ],
            'glpi_plugin_formcreator_sections' => [
                'id', 'name', 'plugin_formcreator_forms_id', 'order', 'uuid'
            ],
            'glpi_plugin_formcreator_questions' => [
                'id', 'name', 'plugin_formcreator_sections_id', 'fieldtype', 'required', 'default_values',
                'itemtype', 'values', 'description', 'row', 'col', 'uuid'
            ],
            'glpi_plugin_formcreator_forms_users' => [
                'plugin_formcreator_forms_id', 'users_id'
            ],
            'glpi_plugin_formcreator_forms_groups' => [
                'plugin_formcreator_forms_id', 'groups_id'
            ],
            'glpi_plugin_formcreator_forms_profiles' => [
                'plugin_formcreator_forms_id', 'profiles_id'
            ],
            'glpi_plugin_formcreator_forms_languages' => [
                'plugin_formcreator_forms_id', 'name'
            ]
        ];

        return $this->checkDbFieldsExists($formcreator_schema);
    }

    protected function processMigration(): bool
    {
        // Count all items to migrate
        $counts = [
            'categories' => $this->countRecords('glpi_plugin_formcreator_categories'),
            'forms' => $this->countRecords('glpi_plugin_formcreator_forms'),
            'sections' => $this->countRecords('glpi_plugin_formcreator_sections'),
            'questions' => $this->countRecords('glpi_plugin_formcreator_questions', ['NOT' => ['fieldtype' => 'description']]),
            'comments' => $this->countRecords('glpi_plugin_formcreator_questions', ['fieldtype' => 'description']),
            'targets_ticket' => $this->countRecords('glpi_plugin_formcreator_targettickets'),
            'targets_problem' => $this->countRecords('glpi_plugin_formcreator_targetproblems'),
            'targets_change' => $this->countRecords('glpi_plugin_formcreator_targetchanges'),
            'translations' => $this->countRecords('glpi_plugin_formcreator_forms_languages')
        ];

        // Set total progress steps
        $this->progress_indicator?->setMaxSteps(
            array_sum($counts)
        );

        // Process each migration step
        $this->processMigrationOfFormCategories();
        $this->processMigrationOfBasicProperties();
        $this->processMigrationOfSections();
        $this->processMigrationOfQuestions();
        $this->processMigrationOfComments();
        $this->updateBlockHorizontalRank();
        $this->processMigrationOfAccessControls();
        $this->processMigrationOfFormTargets();
        $this->processMigrationOfTranslations();

        $this->progress_indicator?->setProgressBarMessage('');
        $this->progress_indicator?->finish();

        return true;
    }

    private function processMigrationOfFormCategories(): void
    {
        $this->progress_indicator?->setProgressBarMessage(__('Importing form categories...'));

        // Retrieve data from glpi_plugin_formcreator_categories table
        $raw_form_categories = $this->db->request([
            'SELECT' => ['id', 'name', 'plugin_formcreator_categories_id'],
            'FROM'   => 'glpi_plugin_formcreator_categories',
            'ORDER'  => ['level ASC']
        ]);

        foreach ($raw_form_categories as $raw_form_category) {
            $data = [
                'name'                => $raw_form_category['name'],
                'forms_categories_id' => $this->getMappedItemTarget(
                    'PluginFormcreatorCategory',
                    $raw_form_category['plugin_formcreator_categories_id']
                )['items_id'] ?? 0
            ];
            $form_category = $this->importItem(
                Category::class,
                $data,
                $data
            );

            $this->mapItem(
                'PluginFormcreatorCategory',
                $raw_form_category['id'],
                Category::class,
                $form_category->getID()
            );

            $this->progress_indicator?->advance();
        }
    }

    private function processMigrationOfBasicProperties(): void
    {
        $this->progress_indicator?->setProgressBarMessage(__('Importing forms...'));

        // Retrieve data from glpi_plugin_formcreator_forms table
        $raw_forms = $this->db->request([
            'SELECT' => [
                'id',
                'description AS header',
                'name',
                'plugin_formcreator_categories_id',
                'entities_id',
                'is_recursive',
                'is_visible AS is_active'
            ],
            'FROM'   => 'glpi_plugin_formcreator_forms'
        ]);

        foreach ($raw_forms as $raw_form) {
            $form = $this->importItem(
                Form::class,
                [
                    'name'                  => $raw_form['name'],
                    'header'                => $raw_form['header'],
                    'forms_categories_id'   => $this->getMappedItemTarget(
                        'PluginFormcreatorCategory',
                        $raw_form['plugin_formcreator_categories_id']
                    )['items_id'] ?? 0,
                    'entities_id'           => $raw_form['entities_id'],
                    'is_recursive'          => $raw_form['is_recursive'],
                    'is_active'             => $raw_form['is_active'],
                    '_from_migration'       =>  true
                ],
                [
                    'name'                => $raw_form['name'],
                    'entities_id'         => $raw_form['entities_id'],
                    'forms_categories_id' => $this->getMappedItemTarget(
                        'PluginFormcreatorCategory',
                        $raw_form['plugin_formcreator_categories_id']
                    )['items_id'] ?? 0,
                ]
            );

            // Store the form for later use
            $this->forms[$raw_form['id']] = $form;

            $this->mapItem(
                'PluginFormcreatorForm',
                $raw_form['id'],
                Form::class,
                $form->getID()
            );

            $this->progress_indicator?->advance();
        }
    }

    private function processMigrationOfSections(): void
    {
        $this->progress_indicator?->setProgressBarMessage(__('Importing sections...'));

        // Retrieve data from glpi_plugin_formcreator_sections table
        $raw_sections = $this->db->request([
            'SELECT' => ['id', 'name', 'plugin_formcreator_forms_id', 'order', 'uuid'],
            'FROM'   => 'glpi_plugin_formcreator_sections'
        ]);

        foreach ($raw_sections as $raw_section) {
            $form_id = $this->getMappedItemTarget(
                'PluginFormcreatorForm',
                $raw_section['plugin_formcreator_forms_id']
            )['items_id'] ?? 0;
            if ($form_id === 0) {
                $this->result->addMessage(MessageType::Error, sprintf(
                    'Section "%s" has no form. It will not be migrated.',
                    $raw_section['name']
                ));
                continue;
            }

            $section = $this->importItem(
                Section::class,
                [
                    Form::getForeignKeyField() => $form_id,
                    'name'                     => $raw_section['name'],
                    'rank'                     => $raw_section['order'] - 1, // New rank is 0-based
                    'uuid'                     => $raw_section['uuid']
                ],
                [
                    'uuid' => $raw_section['uuid']
                ]
            );

            $this->mapItem(
                'PluginFormcreatorSection',
                $raw_section['id'],
                Section::class,
                $section->getID()
            );

            $this->progress_indicator?->advance();
        }
    }

    private function processMigrationOfQuestions(): void
    {
        $this->progress_indicator?->setProgressBarMessage(__('Importing questions...'));

        // Process questions
        $raw_questions = array_values(iterator_to_array($this->db->request([
            'SELECT' => [
                'id',
                'name',
                'plugin_formcreator_sections_id',
                'fieldtype',
                'required',
                'default_values',
                'itemtype',
                'values',
                'description',
                'row',
                'col',
                'uuid'
            ],
            'FROM'   => 'glpi_plugin_formcreator_questions',
            'WHERE'  => ['NOT' => ['fieldtype' => 'description']],
            'ORDER'  => ['plugin_formcreator_sections_id', 'row', 'col']
        ])));

        foreach ($raw_questions as $raw_question) {
            $section_id = $this->getMappedItemTarget(
                'PluginFormcreatorSection',
                $raw_question['plugin_formcreator_sections_id']
            )['items_id'] ?? 0;
            if ($section_id === 0) {
                $this->result->addMessage(MessageType::Error, sprintf(
                    'Question "%s" has no section. It will not be migrated.',
                    $raw_question['name']
                ));
                continue;
            }

            $fieldtype = $raw_question['fieldtype'];
            $type_class = $this->getTypesConvertMap()[$fieldtype] ?? null;

            $default_value = null;
            $extra_data = null;
            if (is_a($type_class, FormQuestionDataConverterInterface::class, true)) {
                $converter     = new $type_class();
                $default_value = $converter->convertDefaultValue($raw_question);
                $extra_data    = $converter->convertExtraData($raw_question);
            }

            $question = new Question();
            $data = array_filter([
                Section::getForeignKeyField() => $section_id,
                'name'                        => $raw_question['name'],
                'type'                        => $type_class,
                'is_mandatory'                => $raw_question['required'],
                'vertical_rank'               => $raw_question['row'],
                'horizontal_rank'             => $raw_question['col'],
                'description'                 => !empty($raw_question['description'])
                                                    ? $raw_question['description']
                                                    : null,
                'default_value'               => $default_value,
                'extra_data'                  => $extra_data,
                'uuid'                        => $raw_question['uuid']
            ], fn ($value) => $value !== null);

            $question = $this->importItem(
                Question::class,
                $data,
                [
                    'uuid' => $raw_question['uuid'],
                ]
            );

            $this->mapItem(
                'PluginFormcreatorQuestion',
                $raw_question['id'],
                Question::class,
                $question->getID()
            );

            $this->progress_indicator?->advance();
        }
    }

    private function processMigrationOfComments(): void
    {
        $this->progress_indicator?->setProgressBarMessage(__('Importing comments...'));

        // Retrieve data from glpi_plugin_formcreator_questions table
        $raw_comments = $this->db->request([
            'SELECT' => [
                'id',
                'name',
                'plugin_formcreator_sections_id',
                'fieldtype',
                'required',
                'default_values',
                'description',
                'row',
                'col',
                'uuid'
            ],
            'FROM'   => 'glpi_plugin_formcreator_questions',
            'WHERE'  => ['fieldtype' => 'description']
        ]);

        foreach ($raw_comments as $raw_comment) {
            $comment = $this->importItem(
                Comment::class,
                [
                    Section::getForeignKeyField() => $this->getMappedItemTarget(
                        'PluginFormcreatorSection',
                        $raw_comment['plugin_formcreator_sections_id']
                    )['items_id'],
                    'name'                        => $raw_comment['name'],
                    'description'                 => $raw_comment['description'],
                    'vertical_rank'               => $raw_comment['row'],
                    'horizontal_rank'             => $raw_comment['col'],
                    'uuid'                        => $raw_comment['uuid']
                ],
                [
                    'uuid' => $raw_comment['uuid']
                ]
            );

            $this->mapItem(
                'PluginFormcreatorQuestion',
                $raw_comment['id'],
                Comment::class,
                $comment->getID()
            );

            $this->progress_indicator?->advance();
        }
    }

    /**
     * Update horizontal rank of questions and comments to be consistent with the new form system
     *
     * @return void
     */
    private function updateBlockHorizontalRank(): void
    {
        $this->progress_indicator?->setProgressBarMessage(__('Updating horizontal rank...'));

        $tables = [Question::getTable(), Comment::getTable()];

        foreach ($tables as $table) {
            // First step: identify sections and vertical ranks with only one element
            $single_blocks = $this->db->request([
                'SELECT' => [
                    'forms_sections_id',
                    'vertical_rank',
                ],
                'FROM' => new QueryUnion([
                    [
                        'SELECT' => ['forms_sections_id', 'vertical_rank'],
                        'FROM'   => Question::getTable(),
                        'WHERE'  => ['NOT' => ['horizontal_rank' => null]]
                    ],
                    [
                        'SELECT' => ['forms_sections_id', 'vertical_rank'],
                        'FROM'   => Comment::getTable(),
                        'WHERE'  => ['NOT' => ['horizontal_rank' => null]]
                    ]
                ]),
                'GROUPBY' => ['forms_sections_id', 'vertical_rank'],
                'HAVING'  => ['COUNT(*) = 1']
            ]);

            // If no unique blocks are found, move to the next table
            if (count($single_blocks) === 0) {
                continue;
            }

            // Build criteria for the update
            $sections_ranks = [];
            foreach ($single_blocks as $block) {
                $sections_ranks[] = [
                    'forms_sections_id' => $block['forms_sections_id'],
                    'vertical_rank'     => $block['vertical_rank']
                ];
            }

            // Update corresponding records
            if (!empty($sections_ranks)) {
                $this->db->update(
                    $table,
                    ['horizontal_rank' => null],
                    ['OR' => $sections_ranks]
                );
            }
        }
    }

    private function processMigrationOfAccessControls(): void
    {
        $this->progress_indicator?->setProgressBarMessage(__('Importing access controls...'));

        // Retrieve data from glpi_plugin_formcreator_forms table
        $raw_form_access_rights = $this->db->request([
            'SELECT' => [
                'access_rights',
                new QueryExpression('glpi_plugin_formcreator_forms.id', 'forms_id'),
                'name', // Added to get form name for status reporting
                new QueryExpression('JSON_ARRAYAGG(users_id)', 'user_ids'),
                new QueryExpression('JSON_ARRAYAGG(groups_id)', 'group_ids'),
                new QueryExpression('JSON_ARRAYAGG(profiles_id)', 'profile_ids')
            ],
            'FROM'   => 'glpi_plugin_formcreator_forms',
            'LEFT JOIN'   => [
                'glpi_plugin_formcreator_forms_users' => [
                    'ON' => [
                        'glpi_plugin_formcreator_forms_users' => 'plugin_formcreator_forms_id',
                        'glpi_plugin_formcreator_forms'       => 'id'
                    ]
                ],
                'glpi_plugin_formcreator_forms_groups' => [
                    'ON' => [
                        'glpi_plugin_formcreator_forms_groups' => 'plugin_formcreator_forms_id',
                        'glpi_plugin_formcreator_forms'        => 'id'
                    ]
                ],
                'glpi_plugin_formcreator_forms_profiles' => [
                    'ON' => [
                        'glpi_plugin_formcreator_forms_profiles' => 'plugin_formcreator_forms_id',
                        'glpi_plugin_formcreator_forms'          => 'id'
                    ]
                ]
            ],
            'GROUPBY' => ['forms_id', 'access_rights']
        ]);

        foreach ($raw_form_access_rights as $form_access_rights) {
            // Use the stored form instead of loading it from the database
            $form = $this->forms[$form_access_rights['forms_id']] ?? null;

            if ($form === null) {
                throw new LogicException("Form with plugin_id {$form_access_rights['forms_id']} not found in memory");
            }

            $strategy_class = $this->getStrategyForAccessTypes()[$form_access_rights['access_rights']] ?? null;
            if ($strategy_class === null) {
                throw new LogicException("Strategy class not found for access type {$form_access_rights['access_rights']}");
            }

            // Create missing access controls for the form
            $this->formAccessControlManager->createMissingAccessControlsForForm($form);

            $form_access_control = $this->importItem(
                FormAccessControl::class,
                [
                    Form::getForeignKeyField() => $form->getID(),
                    'strategy'                 => $strategy_class,
                    '_config'                  => self::getStrategyConfigForAccessTypes($form_access_rights)->jsonSerialize(),
                    'is_active'                => true
                ],
                [
                    Form::getForeignKeyField() => $form->getID(),
                    'strategy'                 => $strategy_class,
                ]
            );

            $this->mapItem(
                'PluginFormcreatorFormAccessType',
                $form_access_rights['forms_id'],
                FormAccessControl::class,
                $form_access_control->getID()
            );

            $this->progress_indicator?->advance();
        }
    }

    /**
     * Process migration of form targets
     *
     * @return void
     */
    private function processMigrationOfFormTargets(): void
    {
        $this->processMigrationOfTickets();
        $this->processMigrationOfProblems();
        $this->processMigrationOfChanges();
    }

    private function processMigrationOfTickets(): void
    {
        $this->progress_indicator?->setProgressBarMessage(__('Importing ticket targets...'));

        $raw_targets = $this->db->request([
            'SELECT'    => [
                'glpi_plugin_formcreator_targettickets.*',
                new QueryExpression(
                    'JSON_REMOVE(JSON_OBJECTAGG(COALESCE(itemtype, "NULL"), COALESCE(items_id, "NULL")), "$.NULL")',
                    'associate_items'
                )
            ],
            'FROM'      => 'glpi_plugin_formcreator_targettickets',
            'LEFT JOIN' => [
                'glpi_plugin_formcreator_items_targettickets' => [
                    'ON' => [
                        'glpi_plugin_formcreator_targettickets'       => 'id',
                        'glpi_plugin_formcreator_items_targettickets' => 'plugin_formcreator_targettickets_id'
                    ]
                ]
            ],
            'GROUPBY' => 'glpi_plugin_formcreator_targettickets.id'
        ]);

        $this->processMigrationOfDestination(
            $raw_targets,
            FormDestinationTicket::class,
            'glpi_plugin_formcreator_targettickets'
        );
        $this->processMigrationOfITILActorsFields(
            FormDestinationTicket::class,
            'glpi_plugin_formcreator_targettickets',
            'PluginFormcreatorTargetTicket'
        );
    }

    private function processMigrationOfProblems(): void
    {
        $this->progress_indicator?->setProgressBarMessage(__('Importing problem targets...'));

        $raw_targets = $this->db->request([
            'FROM' => 'glpi_plugin_formcreator_targetproblems'
        ]);

        $this->processMigrationOfDestination(
            $raw_targets,
            FormDestinationProblem::class,
            'glpi_plugin_formcreator_targetproblems'
        );
        $this->processMigrationOfITILActorsFields(
            FormDestinationProblem::class,
            'glpi_plugin_formcreator_targetproblems',
            'PluginFormcreatorTargetProblem'
        );
    }

    private function processMigrationOfChanges(): void
    {
        $this->progress_indicator?->setProgressBarMessage(__('Importing change targets...'));

        $raw_targets = $this->db->request([
            'FROM' => 'glpi_plugin_formcreator_targetchanges'
        ]);

        $this->processMigrationOfDestination(
            $raw_targets,
            FormDestinationChange::class,
            'glpi_plugin_formcreator_targetchanges'
        );
        $this->processMigrationOfITILActorsFields(
            FormDestinationChange::class,
            'glpi_plugin_formcreator_targetchanges',
            'PluginFormcreatorTargetChange'
        );
    }

    /**
     * Process migration of form destinations for a given destination type and target table
     *
     * @param \DBmysqlIterator $raw_targets The raw targets to process
     * @param class-string<AbstractCommonITILFormDestination> $destinationClass The destination class
     * @param string $targetTable The target table name
     * @throws LogicException
     */
    private function processMigrationOfDestination(
        \DBmysqlIterator $raw_targets,
        string $destinationClass,
        string $targetTable
    ): void {
        foreach ($raw_targets as $raw_target) {
            $form_id = $this->getMappedItemTarget(
                'PluginFormcreatorForm',
                $raw_target['plugin_formcreator_forms_id']
            )['items_id'] ?? 0;

            $form = new Form();
            if (!$form->getFromDB($form_id)) {
                throw new LogicException("Form with id {$raw_target['plugin_formcreator_forms_id']} not found");
            }

            $fields_config = [];
            $configurable_fields = (new $destinationClass())->getConfigurableFields();
            foreach ($configurable_fields as $configurable_field) {
                /** @var AbstractConfigField $configurable_field */
                if ($configurable_field instanceof DestinationFieldConverterInterface) {
                    $fields_config[$configurable_field::getKey()] = $configurable_field->convertFieldConfig(
                        $this,
                        $form,
                        $raw_target
                    )->jsonSerialize();
                }

                if ($configurable_field instanceof ContentField) {
                    $fields_config[$configurable_field::getAutoConfigKey()] = strip_tags($raw_target['content']) === '##FULLFORM##';
                }
            }

            $destination = $this->importItem(
                FormDestination::class,
                [
                    Form::getForeignKeyField() => $form->getID(),
                    'itemtype'                 => $destinationClass,
                    'name'                     => $raw_target['name'],
                    'config'                   => $fields_config
                ],
                [
                    Form::getForeignKeyField() => $form->getID(),
                    'itemtype'                 => $destinationClass,
                    'name'                     => $raw_target['name']
                ]
            );

            $this->mapItem(
                'PluginFormcreatorTarget' . basename($targetTable),
                $raw_target['id'],
                FormDestination::class,
                $destination->getID()
            );

            $this->progress_indicator?->advance();
        }
    }

    /**
     * Process migration of ITIL actors fields for a given destination type and target table
     *
     * @param class-string<AbstractCommonITILFormDestination> $destinationClass The destination class
     * @param string $targetTable The target table name
     * @param string $fcDestinationClass The form creator destination class
     * @throws LogicException
     */
    private function processMigrationOfITILActorsFields(
        string $destinationClass,
        string $targetTable,
        string $fcDestinationClass
    ): void {
        $targets_actors     = [];
        $raw_targets_actors = $this->db->request([
            'SELECT' => [
                'items_id',
                'actor_role',
                'actor_type',
                'actor_value'
            ],
            'FROM' => 'glpi_plugin_formcreator_targets_actors',
            'WHERE' => [
                'itemtype' => $fcDestinationClass
            ]
        ]);

        foreach ($raw_targets_actors as $raw_target_actor) {
            $target_id = $this->getMappedItemTarget(
                'PluginFormcreatorTarget' . basename($targetTable),
                $raw_target_actor['items_id']
            )['items_id'] ?? 0;

            if ($target_id === 0) {
                throw new LogicException("Destination for target id {$raw_target_actor['items_id']} not found");
            }

            $targets_actors[$target_id][$raw_target_actor['actor_role']][$raw_target_actor['actor_type']][] =
                $raw_target_actor['actor_value'];
        }

        foreach ($targets_actors as $destination_id => $actors) {
            $destination = new FormDestination();
            if (!$destination->getFromDB($destination_id)) {
                throw new LogicException("Destination with id {$destination_id} not found");
            }

            $fields_config = json_decode($destination->fields['config'], true);
            $configurable_fields = (new $destinationClass())->getConfigurableFields();
            $configurable_fields = array_filter(
                $configurable_fields,
                fn ($field) => $field instanceof ITILActorField
            );

            foreach ($configurable_fields as $configurable_field) {
                /** @var ITILActorField $configurable_field */
                $fields_config[$configurable_field::getKey()] = $configurable_field->convertFieldConfig(
                    $this,
                    $destination->getItem(),
                    $actors
                )->jsonSerialize();
            }

            if (
                !$destination->update([
                    'id'     => $destination->getID(),
                    'config' => $fields_config
                ])
            ) {
                throw new LogicException("Failed to update destination with id {$destination->getID()}");
            }
        }
    }

    private function processMigrationOfTranslations(): void
    {
        $this->progress_indicator?->setProgressBarMessage(__('Importing translations...'));

        // Retrieve data from glpi_plugin_formcreator_forms_languages table
        $raw_languages = $this->db->request([
            'SELECT' => [
                'plugin_formcreator_forms_id',
                'name'
            ],
            'FROM'   => 'glpi_plugin_formcreator_forms_languages',
        ]);

        foreach ($raw_languages as $raw_language) {
            $translations = $this->getTranslationsFromFile(
                $raw_language['plugin_formcreator_forms_id'],
                $raw_language['name']
            );

            // Skip if no translations found
            if (empty($translations)) {
                continue;
            }

            // Decode HTML entities in the keys
            $decoded_translations = [];
            foreach ($translations as $key => $translation) {
                $decoded_key = html_entity_decode($key);
                $decoded_translations[$decoded_key] = $translation;
            }
            $translations = $decoded_translations;

            $form_id = $this->getMappedItemTarget(
                'PluginFormcreatorForm',
                $raw_language['plugin_formcreator_forms_id']
            )['items_id'] ?? 0;

            $form = new Form();
            if (!$form->getFromDB($form_id)) {
                throw new LogicException("Form with id {$raw_language['plugin_formcreator_forms_id']} not found");
            }

            foreach ($form->listTranslationsHandlers() as $handlers) {
                foreach ($handlers as $handler) {
                    if (isset($translations[$handler->getValue()])) {
                        $this->importItem(
                            FormTranslation::class,
                            [
                                FormTranslation::$items_id => $handler->getItem()->getID(),
                                FormTranslation::$itemtype => $handler->getItem()->getType(),
                                'key'                      => $handler->getKey(),
                                'language'                 => $raw_language['name'],
                                'translations'             => ['one' => $translations[$handler->getValue()]]
                            ],
                            [
                                FormTranslation::$items_id => $handler->getItem()->getID(),
                                FormTranslation::$itemtype => $handler->getItem()->getType(),
                                'key'                      => $handler->getKey(),
                                'language'                 => $raw_language['name'],
                            ]
                        );
                    }
                }
            }

            $this->progress_indicator?->advance();
        }
    }

    /**
     * Get translations from a formcreator translation file
     *
     * @param int $form_id The form ID
     * @param string $language The language code
     * @return array Translation key-value pairs
     */
    protected function getTranslationsFromFile(int $form_id, string $language): array
    {
        $file_path = implode('/', [
            GLPI_LOCAL_I18N_DIR,
            'formcreator',
            sprintf('form_%d_%s.php', $form_id, $language)
        ]);

        if (file_exists($file_path)) {
            return include $file_path;
        }

        return [];
    }
}
