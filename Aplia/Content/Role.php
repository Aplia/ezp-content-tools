<?php
namespace Aplia\Content;

use cash\LRUCache;
use eZPersistentObject;
use eZDB;
use eZRole;
use eZPolicy;
use eZContentClass;
use eZContentObject;
use eZContentObjectTreeNode;
use eZSection;
use eZUser;
use eZINI;
use Aplia\Support\Arr;
use Aplia\Content\Exceptions\TypeError;
use Aplia\Content\Exceptions\ObjectDoesNotExist;
use Aplia\Content\Exceptions\ObjectAlreadyExist;
use Aplia\Content\Exceptions\ValueError;

/**
 * Class which provides easy manipulation of roles. Roles can be created with
 * create() or existing roles can be modified using update().
 * Note: Role does not have a unique ID to use as a reference, instead use the name
 * of the role. If you know the ID then you may pass it.
 * 
 * Examples:
 * 
 * @code
 * // Create a role which has access to all modules/functions
 * $role = new Role(array('name' => 'Administrator'));
 * $role->addPolicy('*');
 * $role->create();
 * 
 * // Create a role which has access to all functions in 'content'
 * $role = new Role(array('name' => 'Administrator'));
 * $role->addPolicy('content');
 * $role->create();
 * 
 * // Create a role which can use the frontpage and read folders
 * $role = new Role(array('name' => 'Anonymous'));
 * $role->addPolicy('user/login');
 * $role->addPolicy('content/read', array(
 *   'class' => array('folder'),
 * ));
 * $role->create();
 * 
 * $role = new Role(array('name' => 'Anonymous'));
 * $role->removePolicy('user/login)
 * $role->update();
 * @endcode
 * 
 * Similarly roles may be added or removed from users/groups, use
 * addAssignment(), removeAssignment(), removeAssignments() and
 * removeAllAssignments().
 * 
 * @code
 * $role = new Role(array('name' => 'Anonymous'));
 * // Add to anonymous user
 * $role->addAssignment('id:anon')
 * // By defining the tree 'users-anon' in content.ini it may be referred using identifier only
 * $role->addAssignment('tree:users-anon')
 * $role->update();
 * @endcode
 */
class Role
{
    public $id;
    public $name;
    public $newName;
    /**
     * Policy changes that are scheduled.
     *
     * @var array
     */
    public $policies = array();
    /**
     * Assignment changes that are scheduled.
     *
     * @var array
     */
    public $assignments = array();
    /**
     * Existing or newly created role object.
     *
     * @var eZRole
     */
    public $role;

    /**
     * LRU cache for looking up content classes.
     *
     * @var \cash\LRUCache
     */
    protected static $classCache = null;
    /**
     * LRU cache for looking up sections.
     *
     * @var \cash\LRUCache
     */
    protected static $sectionCache = null;
    protected $isPoliciesLoaded;

    /**
     * Construct role object with optional parameters.
     *
     * @param array $params
     */
    public function __construct(array $params = null)
    {
        if ($params) {
            $this->set($params);
        }
    }

    /**
     * Sets various parameters on role object, support parameters are:
     * 
     * - name - Name of role, used as a reference. For new roles this is
     *          also used as the stored name.
     * - newName - Rename role to this name on update().
     * - id - ID of role to reference, not recommended as it may change over time.
     * - role - The existing eZRole object to work on, can be passed if you already have the object.
     *
     * @param array $params
     * @return self
     */
    public function set(array $params = null)
    {
        if ($params) {
            if (isset($params['name'])) {
                $this->name = $params['name'];
            }
            if (isset($params['newName'])) {
                $this->newName = $params['newName'];
            }
            if (isset($params['id'])) {
                $this->id = $params['id'];
            }
            if (isset($params['role'])) {
                $this->role = $params['role'];
            }
        }

        return $this;
    }

    /**
     * Schedules a new policy to the role.
     * The policy is specified with access to a module and a function, as well
     * as values for the module. The module may be specified in different forms:
     * 
     * - '*' for access to all modules, e.g. an admin user.
     * - '<module>' for access to all functions in a module
     * - '<module>/<function>' for access to a specific function in a module
     * 
     * Values are only used when specifying a function, and is an associative
     * array where the key is the type of policy limitation and the value is
     * the limitation value.
     * 
     * The following limitation types are supported:
     * - class - Value is an array of class identifiers or class objects
     * - section - Value is an array of section identifiers or section objects
     * - subtree - Value is an array of node IDs, node UUIDs, tree identifiers,
     *             eZContentObjectTreeNode objects or eZContentObject objects.
     * 
     * @code
     * $role->addPolicy('*');
     * $role->addPolicy('content');
     * $role->addPolicy('content/read', array(
     *   'class' => array('folder'),
     *   'subtree' => array(2, 'tree:users', 'ab2134cd'),
     * ));
     * @endcode
     *
     * @param string $module
     * @param array $values
     * @return self
     */
    public function addPolicy($module, array $values=null)
    {
        $this->policies[] = array(
            'status' => 'new',
            'module' => $module,
            'values' => $values,
        );

        return $this;
    }

    /**
     * Schedules removal of an existing policy from the role.
     * The policy is specified with access to a module and a function, as well
     * as values for the module. The module may be specified in different forms:
     * 
     * - '*' for access to all modules, e.g. an admin user.
     * - '<module>' for access to all functions in a module
     * - '<module>/<function>' for access to a specific function in a module
     * 
     * Values are only used when specifying a function, and is an associative
     * array where the key is the type of policy limitation and the value is
     * the limitation value.
     * 
     * The following limitation types are supported:
     * - class - Value is an array of class identifiers or class objects
     * - section - Value is an array of section identifiers or section objects
     * - subtree - Value is an array of node IDs, node UUIDs, tree identifiers,
     *             eZContentObjectTreeNode objects or eZContentObject objects.
     * 
     * @code
     * $role->removePolicy('*');
     * $role->removePolicy('content');
     * $role->removePolicy('content/read', array(
     *   'class' => array('folder'),
     * ));
     * @endcode
     *
     * @param string $module
     * @param array $values
     * @return self
     */
    public function removePolicy($module, array $values=null)
    {
        $this->policies[] = array(
            'status' => 'remove',
            'module' => $module,
            'values' => $values,
        );

        return $this;
    }

    /**
     * Schedules the role to be assigned to specified user with optional
     * limitation.
     * The user parameter may be specified in different ways, either as
     * as a numerical ID, a string with a UUID, a string with an email address,
     * a special user with 'id:<identifier>', a tree identifier 'tree:<identifier'>,
     * a uuid 'uuid:<uuid>', or an object of the following
     * classes: eZUser, eZContentObject, eZContentObjectTreeNode.
     * 
     * The special user identifier may be one of:
     * - anon - The anonymous user (defined in site.ini UserSettings/AnonymousUserId)
     * - admin - The administrator user (defined in site.ini UserSettings/AdminUserId)
     * 
     * If $limitId is 'subtree' the value can be specified in different ways to
     * specify the subtree node.
     * A numerical ID, a string with a UUID, tree identifier 'tree:<identifier>,
     * or an object of the following classes: eZContentObject, eZContentObjectTreeNode.
     * 
     * // With user/object ID
     * $role->addAssignment(14)
     * // With object UUID
     * $role->addAssignment("abcdef")
     * // With limitation, using tree identifier
     * $role->addAssignment($user, "subtree", "tree:media")
     * // With limitation, using uuid
     * $role->addAssignment($user, "subtree", "b2345c")
     *
     * @param mixed $user User object, ID or UUID to assign to
     * @param string|null $limitId Identifier of limitation for assignment
     * @param mixed $limitValue Value for the limitation
     * @return self
     */
    public function addAssignment($user, $limitId = null, $limitValue = null)
    {
        $assignment = $this->makeAssigmentSchedule($user, $limitId, $limitValue, 'new');
        $this->assignments[] = $assignment;
        return $this;
    }

    /**
     * Schedules the role assignment to be removed from a specified user with
     * optional limitation. Only the exact matching assignment is removed,
     * so if only user is specified it will not remove assignment for the same
     * user if it has a limitation.
     * Parameters are the same as for addAssignment().
     * 
     * @param mixed $user User object, ID or UUID to assign to
     * @param string|null $limitId Identifier of limitation for assignment
     * @param mixed $limitValue Value for the limitation
     * @return self
     **/
    public function removeAssignment($user, $limitId = null, $limitValue = null)
    {
        $assignment = $this->makeAssigmentSchedule($user, $limitId, $limitValue, 'remove');
        $assignment['context'] = 'limitation';
        $this->assignments[] = $assignment;
        return $this;
    }

    /**
     * Schedules the role assignment to be entirely removed from a specified user.
     * User parameter is the same as for addAssignment().
     * 
     * @param mixed $user User object, ID or UUID to assign to
     * @return self
     **/
    public function removeAssignments($user)
    {
        $assignment = $this->makeAssigmentSchedule($user, $limitId, $limitValue, 'remove');
        $assignment['context'] = 'user';
        $this->assignments[] = $assignment;
        return $this;
    }

    /**
     * Schedules the role assignment to be entirely removed for all users.
     * 
     * @return self
     **/
    public function removeAllAssignments()
    {
        $assignment = array(
            'status' => 'remove',
            'context' => 'all',
            'userId' => null,
            'limitId' => null,
            'limitValue' => null,
            'limitDbValue' => null,
        );
        $this->assignments[] = $assignment;
        return $this;
    }

    /**
     * Schedules assigned change for the current role to a specified user,
     * with optional limitation.
     *
     * @param mixed $user
     */
    protected function makeAssigmentSchedule($user, $limitId = null, $limitValue = null, $status)
    {
        $userId = $this->processUserValue($user);
        if ($limitId && $limitValue) {
            $limitDbValue = null;
            if ($limitId === 'subtree') {
                // Figure out node ID based on input value
                if (is_object($limitValue)) {
                    if ($limitValue instanceof eZContentObjectTreeNode) {
                    } else if ($limitValue instanceof eZContentObject) {
                        $node = $limitValue->mainNode();
                        if (!$node) {
                            throw new ObjectDoesNotExist("Content object with ID " . $limitValue->attribute('id') . " does not have a main node, cannot use as assignment limitation");
                        }
                        $limitValue = (int)$node->attribute('node_id');
                        $limitDbValue = $node->attribute('path_string');
                    } else {
                        throw new TypeError("Unsupported object type " . get_class($user) . " used for role assignment");
                    }
                } else if (is_numeric($limitValue)) {
                    $node = eZContentObjectTreeNode::fetch($limitValue);
                    if (!$node) {
                        throw new ObjectDoesNotExist("Content node with ID '$limitValue' does not exist, cannot use as assignment limitation");
                    }
                    $limitValue = (int)$limitValue;
                    $limitDbValue = $node->attribute('path_string');
                } else if (is_string($limitValue)) {
                    if (preg_match("/^tree:(.*)$/", $limitValue, $matches)) {
                        $nodeId = ContentObject::mapTreeIdentifierToNode($matches[1]);
                        if (!$nodeId) {
                            throw new TypeError("Node tree identifier '$limitValue' cannot be used as the tree does not exist");
                        }
                        $node = eZContentObjectTreeNode::fetch($nodeId);
                        if (!$node) {
                            throw new TypeError("Node tree identifier '$limitValue' cannot be used as the node does not exist");
                        }
                    } else if (preg_match("/^uuid:(.*)$/", $limitValue, $matches)) {
                        $uuid = $matches[1];
                        $node = eZContentObjectTreeNode::fetchByRemoteID($uuid);
                        if (!$node) {
                            throw new ObjectDoesNotExist("Content node with UUID '$uuid' does not exist, cannot use as assignment limitation");
                        }
                    } else {
                        $node = eZContentObjectTreeNode::fetchByRemoteID($limitValue);
                        if (!$node) {
                            throw new ObjectDoesNotExist("Content node with UUID '$limitValue' does not exist, cannot use as assignment limitation");
                        }
                    }
                    $limitValue = (int)$node->attribute('node_id');
                    $limitDbValue = $node->attribute('path_string');
                } else {
                    throw new TypeError("Unsupported type " . gettype($limitValue) . " for limitation value used for role assignment");
                }
            }
            $assignment = array(
                'status' => $status,
                'userId' => $userId,
                'limitId' => $limitId,
                'limitValue' => $limitValue,
                'limitDbValue' => $limitDbValue,
            );
        } else {
            $assignment = array(
                'status' => $status,
                'userId' => $userId,
                'limitId' => null,
                'limitValue' => null,
                'limitDbValue' => null,
            );
        }
        return $assignment;
    }

    /**
     * Loads the role from database if it is not already set and returns
     * the role.
     * Tries to load role using either name or ID.
     *
     * @return eZRole
     */
    public function load()
    {
        if ($this->role !== null) {
            return $this->role;
        }
        if ($this->id) {
            $this->role = eZRole::fetch($this->id);
        } else if ($this->name) {
            $this->role = eZRole::fetchByName($this->name);
        }
        return $this->role;
    }

    /**
     * Loads all policies on the exsting role and adds them to the
     * list of policies.
     *
     * @return self
     */
    public function loadPolicies()
    {
        if ($this->isPoliciesLoaded) {
            return;
        }
        $policies = eZPersistentObject::fetchObjectList(
            eZPolicy::definition(),
            /*field_filters*/null,
            array(
                'role_id' => $this->role->attribute('id'),
                'module_name' => $module,
                'function_name' => $function,
            ),
            array('module_name' => 'asc', 'function_name' => 'asc'),
            /*limit*/null, /*asObject*/true
        );
        // Decode policy entries into our array structures
        foreach ($policies as $policy) {
            $existingLimitations = $policy->limitationList();
            $existingLimitationArray = array();
            foreach ($existingLimitations as $limitation) {
                $identifier = $limitation->attribute('identifier');
                $existingLimitationArray[$identifier] = $limitation->allValues();
            }
            $module = $policy->attribute('module_name');
            if ($module !== '*' && $policy->attribute('function_name') !== '*') {
                $module .= "/" . $policy->attribute('function_name');
            }
            $this->policies[] = array(
                'status' => 'nop',
                'module' => $module,
                'values' => $existingLimitationArray,
            );
        }
        $this->isPoliciesLoaded = true;
        return $this;
    }

    /**
     * Creates the role, adds policies and assignments, and returns the eZRole object.
     *
     * @throws \Aplia\Content\Exceptions\ObjectAlreadyExists if the role already exists
     * @throws \Aplia\Content\Exceptions\ValueError if no role name was specified
     * @return \eZRole
     */
    public function create()
    {
        if ($this->id) {
            $role = eZRole::fetch($this->id);
            if ($role) {
                throw new ObjectAlreadyExist("Cannot create a new role, role with ID {$this->id} already exists");
            }
        } else if ($this->name) {
            $role = eZRole::fetchByName($this->name);
            if ($role) {
                throw new ObjectAlreadyExist("Cannot create a new role, role with name {$this->name} already exists");
            }
        } else {
            throw new ValueError("Cannot create a new role, no name was specified");
        }

        $data = array(
            'name' => $this->name,
        );
        if ($this->id) {
            $data['id'] = $this->id;
        }
        $role = new eZRole($data);
        $role->store();
        $this->role = $role;

        foreach ($this->policies as $idx => $policy) {
            $status = $policy['status'];
            if ($status === 'new') {
                $this->createPolicy($policy['module'], $policy['values']);
            } else if ($status === 'remove') {
                // No point in removing for new roles, skip it
            } else if ($status === 'nop') {
            }
            unset($this->policies[$idx]);
        }

        $this->processAssignments(/*isCreate*/true);

        return $this->role;
    }

    /**
     * Updates the existing roles, by adding/removing policies and assignments, and returns the eZRole object.
     *
     * @throws \Aplia\Content\Exceptions\ObjectDoesNotExist if the role does not exist
     * @throws \Aplia\Content\Exceptions\ValueError if no role name was specified
     * @return \eZRole
     */
    public function update()
    {
        $role = $this->load();
        if (!$role) {
            if ($this->id) {
                throw new ObjectDoesNotExist("Cannot update role, role with ID {$this->id} does not exist");
            } else if ($this->name) {
                throw new ObjectAlreadyExist("Cannot update role, role with name {$this->name} does not exist");
            } else {
                throw new ValueError("Cannot update role, no name or id was specified");
            }
        }

        $data = array(
            'name' => $this->name,
        );
        if ($this->id) {
            $data['id'] = $this->id;
        }
        $isModified = false;
        if ($this->newName) {
            $role->setAttribute('name', $this->newName);
            $isModified = true;
        }
        if ($isModified) {
            $role->store();
        }

        foreach ($this->policies as $policy) {
            $status = $policy['status'];
            if ($status === 'new') {
                $this->createPolicy($policy['module'], $policy['values']);
            } else if ($status === 'remove') {
                $this->deletePolicy($policy['module'], $policy['values']);
            }
        }

        $this->processAssignments();

        return $this->role;
    }

    /**
     * Creates a new policy for the current role and returns the policy.
     *
     * @param string $module Module/function used in policy
     * @param array $values Associative array of types => values for policy
     * @return eZRole
     */
    protected function createPolicy($module, array $values = null)
    {
        $originalModule = $module;
        if (preg_match("|^([^/]+)/(.*)$|", $module, $matches)) {
            $module = $matches[1];
            $function = $matches[2];
        } else {
            $function = '*';
        }
        $limitations = array();
        if ($values === null) {
            $values = array();
        }
        foreach ($values as $type => $value) {
            if (!is_array($value)) {
                $value = array($value);
            }
            $limitationValues = $value;
            if ($type === 'class' || $type === 'Class') {
                $type = 'Class';
                // Decode values into IDs
                $limitationValues = array();
                foreach ($value as $item) {
                    if (is_object($item)) {
                        if ($item instanceof eZContentClass) {
                            $limitationValues[] = (int)$item->attribute('id');
                        } else {
                            throw new TypeError("Unsupported value type " . gettype($item) . " for policy $originalModule and parameter $type");
                        }
                    } else if (is_numeric($item)) {
                        $limitationValues[] = (int)$item;
                    } else if (is_string($item)) {
                        $class = $this->lookupContentClass($item);
                        if (!$class) {
                            throw new ObjectDoesNotExist("Policy $originalModule and parameter $type referenced content-class $item but it does not exist");
                        }
                        $limitationValues[] = (int)$class->attribute('id');
                    } else {
                        throw new TypeError("Unsupported value " . var_export($item, true) . " for policy $originalModule and parameter $type");
                    }
                }
            } else if ($type === 'section' || $type === 'Section') {
                $type = 'Section';
                // Decode values into IDs
                $limitationValues = array();
                foreach ($value as $item) {
                    if (is_object($item)) {
                        if ($item instanceof eZSection) {
                            $limitationValues[] = (int)$item->attribute('id');
                        } else {
                            throw new TypeError("Unsupported value type " . gettype($item) . " for policy $originalModule and parameter $type");
                        }
                    } else if (is_numeric($item)) {
                        $limitationValues[] = (int)$item;
                    } else if (is_string($item)) {
                        $section = $this->lookupSection($item);
                        if (!$section) {
                            throw new ObjectDoesNotExist("Policy $originalModule and parameter $type referenced content-class $item but it does not exist");
                        }
                        $limitationValues[] = (int)$section->attribute('id');
                    } else {
                        throw new TypeError("Unsupported value " . var_export($item, true) . " for policy $originalModule and parameter $type");
                    }
                }
            } else if ($type === 'subtree' || $type === 'Subtree') {
                $type = 'Subtree';
                // Decode values into IDs
                $limitationValues = array();
                foreach ($value as $item) {
                    if (is_object($item)) {
                        if ($item instanceof eZContentObjectTreeNode) {
                            $limitationValues[] = $item->attribute('path_string');
                        } else if ($item instanceof eZContentObject) {
                            $mainNode = $item->attribute('main_node');
                            if (!$mainNode) {
                                throw new TypeError("Value " . gettype($item) . " for policy $originalModule and parameter $type cannot be used as it has no main node");
                            }
                            $limitationValues[] = $mainNode->attribute('path_string');
                        } else {
                            throw new TypeError("Unsupported value type " . gettype($item) . " for policy $originalModule and parameter $type");
                        }
                    } else if (is_numeric($item)) {
                        $node = eZContentObjectTreeNode::fetch($item);
                        if (!$node) {
                            throw new TypeError("Node ID $item for policy $originalModule and parameter $type cannot be used as the node does not exist");
                        }
                        $limitationValues[] = $node->attribute('path_string');
                    } else if (preg_match("/^tree:(.*)$/", $item, $matches)) {
                        $nodeId = ContentObject::mapTreeIdentifierToNode($matches[1]);
                        if (!$nodeId) {
                            throw new TypeError("Node tree identifier '$item' for policy $originalModule and parameter $type cannot be used as the tree does not exist");
                        }
                        $node = eZContentObjectTreeNode::fetch($nodeId);
                        if (!$node) {
                            throw new TypeError("Node tree identifier '$item' for policy $originalModule and parameter $type cannot be used as the node does not exist");
                        }
                        $limitationValues[] = $node->attribute('path_string');
                    } else if (is_string($item)) {
                        $node = eZContentObjectTreeNode::fetchByRemoteID($item);
                        if (!$node) {
                            throw new TypeError("UUID '$item' for policy $originalModule and parameter $type cannot be used as the node does not exist");
                        }
                        $limitationValues[] = $node->attribute('path_string');
                    } else {
                        throw new TypeError("Unsupported value " . var_export($item, true) . " for policy $originalModule and parameter $type");
                    }
                }
            } else {
                $type = ucfirst($type);
            }
            $limitations[$type] = $limitationValues;
        }
        $policy = $this->role->appendPolicy($module, $function, $limitations);
        return $policy;
    }

    /**
     * Deletes an existing policy for the current role. It will try and find
     * a policy that matches both the module/function name and all values.
     *
     * @param string $module Module/function used in policy
     * @param array $values Associative array of types => values for policy
     * @return void
     */
    public function deletePolicy($module, array $values = null)
    {
        $originalModule = $module;
        if (preg_match("|^([^/]+)/(.*)$|", $module, $matches)) {
            $module = $matches[1];
            $function = $matches[2];
        } else {
            $function = '*';
            $values = null;
        }
        $limitations = array();
        if ($values === null) {
            $values = array();
        }
        foreach ($values as $type => $value) {
            if (!is_array($value)) {
                $value = array($value);
            }
            $limitationValues = $value;
            if ($type === 'class' || $type === 'Class') {
                $type = 'Class';
                // Decode values into IDs
                $limitationValues = array();
                foreach ($value as $item) {
                    if (is_object($item)) {
                        if ($item instanceof eZContentClass) {
                            $limitationValues[] = (int)$item->attribute('id');
                        } else {
                            throw new TypeError("Unsupported value type " . gettype($item) . " for policy $originalModule and parameter $type");
                        }
                    } else if (is_numeric($item)) {
                        $limitationValues[] = (int)$item;
                    } else if (is_string($item)) {
                        $class = $this->lookupContentClass($item);
                        if (!$class) {
                            throw new ObjectDoesNotExist("Policy $originalModule and parameter $type referenced content-class $item but it does not exist");
                        }
                        $limitationValues[] = (int)$class->attribute('id');
                    } else {
                        throw new TypeError("Unsupported value " . var_export($item, true) . " for policy $originalModule and parameter $type");
                    }
                }
            } else if ($type === 'section' || $type === 'Section') {
                $type = 'Section';
                // Decode values into IDs
                $limitationValues = array();
                foreach ($value as $item) {
                    if (is_object($item)) {
                        if ($item instanceof eZSection) {
                            $limitationValues[] = (int)$item->attribute('id');
                        } else {
                            throw new TypeError("Unsupported value type " . gettype($item) . " for policy $originalModule and parameter $type");
                        }
                    } else if (is_numeric($item)) {
                        $limitationValues[] = (int)$item;
                    } else if (is_string($item)) {
                        $section = $this->lookupSection($item);
                        if (!$section) {
                            throw new ObjectDoesNotExist("Policy $originalModule and parameter $type referenced content-class $item but it does not exist");
                        }
                        $limitationValues[] = (int)$section->attribute('id');
                    } else {
                        throw new TypeError("Unsupported value " . var_export($item, true) . " for policy $originalModule and parameter $type");
                    }
                }
            } else if ($type === 'subtree' || $type === 'Subtree') {
                $type = 'Subtree';
                // Decode values into IDs
                $limitationValues = array();
                foreach ($value as $item) {
                    if (is_object($item)) {
                        if ($item instanceof eZContentObjectTreeNode) {
                            $limitationValues[] = $item->attribute('path_string');
                        } else if ($item instanceof eZContentObject) {
                            $mainNode = $item->attribute('main_node');
                            if (!$mainNode) {
                                throw new TypeError("Value " . gettype($item) . " for policy $originalModule and parameter $type cannot be used as it has no main node");
                            }
                            $limitationValues[] = $mainNode->attribute('path_string');
                        } else {
                            throw new TypeError("Unsupported value type " . gettype($item) . " for policy $originalModule and parameter $type");
                        }
                    } else if (is_numeric($item)) {
                        $node = eZContentObjectTreeNode::fetch($item);
                        if (!$node) {
                            throw new TypeError("Node ID $item for policy $originalModule and parameter $type cannot be used as the node does not exist");
                        }
                        $limitationValues[] = $node->attribute('path_string');
                    } else if (preg_match("/^tree:(.*)$/", $item, $matches)) {
                        $nodeId = ContentObject::mapTreeIdentifierToNode($matches[1]);
                        if (!$nodeId) {
                            throw new TypeError("Node tree identifier '$item' for policy $originalModule and parameter $type cannot be used as the tree does not exist");
                        }
                        $node = eZContentObjectTreeNode::fetch($nodeId);
                        if (!$node) {
                            throw new TypeError("Node tree identifier '$item' for policy $originalModule and parameter $type cannot be used as the node does not exist");
                        }
                        $limitationValues[] = $node->attribute('path_string');
                    } else if (is_string($item)) {
                        $node = eZContentObjectTreeNode::fetchByRemoteID($item);
                        if (!$node) {
                            throw new TypeError("UUID $item for policy $originalModule and parameter $type cannot be used as the node does not exist");
                        }
                        $limitationValues[] = $node->attribute('path_string');
                    } else {
                        throw new TypeError("Unsupported value " . var_export($item, true) . " for policy $originalModule and parameter $type");
                    }
                }
            } else {
                $type = ucfirst($type);
            }
            $limitations[$type] = $limitationValues;
        }

        $policies = eZPersistentObject::fetchObjectList(
            eZPolicy::definition(),
            /*field_filters*/null,
            array(
                'role_id' => $this->role->attribute('id'),
                'module_name' => $module,
                'function_name' => $function,
            ),
            array('module_name' => 'asc', 'function_name' => 'asc'),
            /*limit*/null, /*asObject*/true
        );
        // If the policy is for the entire module or all modules then remove all policies that match
        if ($module === '*' || $function === '*') {
            foreach ($policies as $policy) {
                $policy->remove();
            }
        } else {
            // Otherwise find policies where limitation values match exactly
            foreach ($policies as $policy) {
                $existingLimitations = $policy->limitationList();
                $existingLimitationArray = array();
                foreach ($existingLimitations as $limitation) {
                    $identifier = $limitation->attribute('identifier');
                    $existingLimitationArray[$identifier] = $limitation->allValues();
                }
                $keys = array_keys($limitations);
                $existingKeys = array_keys($existingLimitationArray);
                // If there is a difference in limitation types then skip it
                if (array_diff($keys, $existingKeys) || array_diff($existingKeys, $keys)) {
                    continue;
                }
                // If the values also differ then skip it
                foreach ($limitations as $type => $limitation) {
                    if (array_diff($limitation, $existingLimitationArray[$type]) || array_diff($existingLimitationArray[$type], $limitation)) {
                        continue 2;
                    }
                }
                echo "Removing policy " . $policy->ID . "\n";
                $policy->remove();
            }
        }
    }

    /**
     * Write back scheduled assignments to database.
     */
    protected function processAssignments($isCreate = false)
    {
        if (!$this->assignments) {
            return;
        }
        foreach ($this->assignments as $idx => $assignment) {
            $status = $assignment['status'];
            if ($status === 'new') {
                $this->role->assignToUser(
                    $assignment['userId'],
                    $assignment['limitId'] ? $assignment['limitId'] : '',
                    $assignment['limitValue'] ? $assignment['limitValue'] : ''
                );
            } else if ($status === 'remove') {
                // No point in removing for new roles, skip it
                if (!$isCreate) {
                    $context = $assignment['context'];
                    if ($context === 'limitation') {
                        $this->deleteLimitedAssignment($assignment['userId'], $assignment['limitId'], $assignment['limitValue'], $assignment['limitDbValue']);
                    } else if ($context === 'user') {
                        $this->role->removeUserAssignment($assignment['userId']);
                    } else if ($context === 'all') {
                        $this->deleteAllAssignments();
                    } else {
                        throw new ValueError("Unknown assignment context value '$context'");
                    }
                }
            } else if ($status === 'nop') {
            }
            unset($this->assignments[$idx]);
        }
    }

    /**
     * Removes role assignment with limitation from user.
     *
     * @param int $userId
     * @param string|null $limitId
     * @param string|null $limitValue
     */
    protected function deleteLimitedAssignment($userId, $limitId, $limitValue, $limitDbValue)
    {
        $role = $this->role;
        $db = eZDB::instance();
        if ($limitId === 'subtree') {
            $limitId = 'Subtree';
            $limitValue = $limitDbValue;
        }
        $limitId = $db->escapeString($limitId ? $limitId : '');
        $limitValue = $db->escapeString($limitValue ? $limitValue : '');
        $query = "DELETE FROM ezuser_role WHERE role_id='{$role->ID}' AND contentobject_id='{$userId}' AND limit_identifier='{$limitId}' AND limit_value='{$limitValue}'";
        $db->query($query);
    }

    /**
     * Removes all role assignment for role.
     */
    protected function deleteAllAssignments()
    {
        $role = $this->role;
        $db = eZDB::instance();
        $db->query("DELETE FROM ezuser_role WHERE role_id='{$role->ID}'");
    }

    /**
     * Decode the user parameter into a user ID
     *
     * @param mixed $user
     * @return int
     */
    protected function processUserValue($user)
    {
        if (is_object($user)) {
            if ($user instanceof eZUser) {
                $userId = $user->attribute('contentobject_id');
            } else if ($user instanceof eZContentObjectTreeNode) {
                $userId = $user->attribute('contentobject_id');
            } else if ($user instanceof eZContentObject) {
                $userId = $user->attribute('id');
            } else {
                throw new TypeError("Unsupported object type " . get_class($user) . " used for role assignment");
            }
        } else if (is_numeric($user)) {
            $userId = $user;
            $user = eZContentObject::fetchByRemoteID($userId);
            if (!$user) {
                throw new ObjectDoesNotExist("User object with ID '$userId' does not exist");
            }
        } else if (is_string($user)) {
            if (substr($user, 0, 3) === 'id:') {
                $id = substr($user, 3);
                $siteIni = eZINI::instance();
                if ($id === 'anon') {
                    $userId = $siteIni->variable('UserSettings', 'AnonymousUserID');
                    $user = eZUser::fetch($userId);
                    if (!$user) {
                        throw new ObjectDoesNotExist("Anonymous user does not exist");
                    }
                } else if ($id === 'admin') {
                    // Admin user is not defined in site.ini by default, allow for it to be set but default to 14
                    $userId = $siteIni->hasVariable('UserSettings', 'AdminUserID') ? (int)$siteIni->variable('UserSettings', 'AdminUserID') : 14;
                    $user = eZUser::fetch($userId);
                    if (!$user) {
                        throw new ObjectDoesNotExist("Admin user does not exist");
                    }
                } else {
                    throw new ValueError("Unsupported user identifier '$id'");
                }
            } else if (substr($user, 0, 5) === 'uuid:') {
                $uuid = substr($user, 5);
                $user = eZContentObject::fetchByRemoteID($uuid);
                if (!$user) {
                    throw new ObjectDoesNotExist("User object with UUID '$uuid' does not exist");
                }
            } else if (substr($user, 0, 5) === 'tree:') {
                $treeId = substr($user, 5);
                $nodeId = ContentObject::mapTreeIdentifierToNode($treeId);
                if (!$nodeId) {
                    throw new TypeError("Node tree identifier '$treeId' cannot be used as the tree does not exist");
                }
                $node = eZContentObjectTreeNode::fetch($nodeId);
                if (!$node) {
                    throw new TypeError("Node tree identifier '$treeId' cannot be used as the node does not exist");
                }
                $userId = (int)$node->attribute('contentobject_id');
                return $userId;
            } else if (strpos($user, "@") !== false) {
                $email = $user;
                $user = eZUser::fetchByEmail($email);
                if (!$user) {
                    throw new ObjectDoesNotExist("User object with email '$email' does not exist");
                }
            } else {
                $uuid = $user;
                $user = eZContentObject::fetchByRemoteID($uuid);
                if (!$user) {
                    throw new ObjectDoesNotExist("User object with UUID '$uuid' does not exist");
                }
            }
            $userId = (int)$user->attribute('contentobject_id');
        } else {
            throw new TypeError("Unsupported type " . gettype($user) . " for user used for role assignment");
        }
        return $userId;
    }

    /**
     * Looks for the content-class with identifier $identifier and returns it.
     * If it is not found returns null.
     * 
     * Classes are cached in memory so repeated calls are cheap.
     *
     * @param string $identifier
     * @return void
     */
    public function lookupContentClass($identifier)
    {
        if (self::$classCache === null) {
            self::$classCache = new LRUCache(100);
        }
        $class = self::$classCache->get($identifier);
        if (!$class) {
            $class = eZContentClass::fetchByIdentifier($identifier);
            if (!$class) {
                return;
            }
            self::$classCache->put($identifier, $class);
        }
        return $class;
    }

    /**
     * Looks for the section with identifier $identifier and returns it.
     * If it is not found returns null.
     * 
     * Sections are cached in memory so repeated calls are cheap.
     *
     * @param string $identifier
     * @return void
     */
    public function lookupSection($identifier)
    {
        if (self::$sectionCache === null) {
            self::$sectionCache = new LRUCache(50);
        }
        $section = self::$sectionCache->get($identifier);
        if (!$section) {
            $section = eZSection::fetchByIdentifier($identifier);
            if (!$section) {
                return;
            }
            self::$sectionCache->put($identifier, $section);
        }
        return $section;
    }
}
