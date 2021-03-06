<?php
///////////////////////////////////////////////////////////////////////////////
//
// © Copyright f-project.net 2010-present.
//
// Licensed under the Apache License, Version 2.0 (the "License");
// you may not use this file except in compliance with the License.
// You may obtain a copy of the License at
//
//     http://www.apache.org/licenses/LICENSE-2.0
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.
//
///////////////////////////////////////////////////////////////////////////////

namespace fproject\amf\acl;

use fproject\amf\AmfException;

class Acl
{
    /**
     * Rule type: allow
     */
    const TYPE_ALLOW = 'TYPE_ALLOW';

    /**
     * Rule type: deny
     */
    const TYPE_DENY  = 'TYPE_DENY';

    /**
     * Rule operation: add
     */
    const OP_ADD = 'OP_ADD';

    /**
     * Rule operation: remove
     */
    const OP_REMOVE = 'OP_REMOVE';

    /**
     * Role registry
     *
     * @var RoleRegistry
     */
    protected $_roleRegistry = null;

    /**
     * Resource tree
     *
     * @var array
     */
    protected $_resources = [];

    /**
     * @var RoleInterface
     */
    protected $_isAllowedRole     = null;

    /**
     * @var ResourceInterface
     */
    protected $_isAllowedResource = null;

    /**
     * @var String
     */
    protected $_isAllowedPrivilege = null;

    /**
     * ACL rules; whitelist (deny everything to all) by default
     *
     * @var array
     */
    protected $_rules = array(
        'allResources' => array(
            'allRoles' => array(
                'allPrivileges' => array(
                    'type'   => self::TYPE_DENY,
                    'assert' => null
                    ),
                'byPrivilegeId' => array()
                ),
            'byRoleId' => array()
            ),
        'byResourceId' => array()
        );

    /**
     * Adds a Role having an identifier unique to the registry
     *
     * The $parents parameter may be a reference to, or the string identifier for,
     * a Role existing in the registry, or $parents may be passed as an array of
     * these - mixing string identifiers and objects is ok - to indicate the Roles
     * from which the newly added Role will directly inherit.
     *
     * In order to resolve potential ambiguities with conflicting rules inherited
     * from different parents, the most recently added parent takes precedence over
     * parents that were previously added. In other words, the first parent added
     * will have the least priority, and the last parent added will have the
     * highest priority.
     *
     * @param  RoleInterface|string $role
     * @param  RoleInterface|string|array $parents
     * @return Acl Provides a fluent interface
     * @throws AmfException
     * @uses   RoleRegistry::add()
     */
    public function addRole($role, $parents = null)
    {
        if (is_string($role)) {
            $role = new Role($role);
        }

        if (!$role instanceof RoleInterface) {
            throw new AmfException('addRole() expects $role to be of type RoleInterface');
        }


        $this->_getRoleRegistry()->add($role, $parents);

        return $this;
    }

    /**
     * Returns the identified Role
     *
     * The $role parameter can either be a Role or Role identifier.
     *
     * @param  RoleInterface|string $role
     * @uses   RoleRegistry::get()
     * @return RoleInterface
     */
    public function getRole($role)
    {
        return $this->_getRoleRegistry()->get($role);
    }

    /**
     * Returns true if and only if the Role exists in the registry
     *
     * The $role parameter can either be a Role or a Role identifier.
     *
     * @param  RoleInterface|string $role
     * @uses   RoleRegistry::has()
     * @return boolean
     */
    public function hasRole($role)
    {
        return $this->_getRoleRegistry()->has($role);
    }

    /**
     * Returns true if and only if $role inherits from $inherit
     *
     * Both parameters may be either a Role or a Role identifier. If
     * $onlyParents is true, then $role must inherit directly from
     * $inherit in order to return true. By default, this method looks
     * through the entire inheritance DAG to determine whether $role
     * inherits from $inherit through its ancestor Roles.
     *
     * @param  RoleInterface|string $role
     * @param  RoleInterface|string $inherit
     * @param  boolean                        $onlyParents
     * @uses   RoleRegistry::inherits()
     * @return boolean
     */
    public function inheritsRole($role, $inherit, $onlyParents = false)
    {
        return $this->_getRoleRegistry()->inherits($role, $inherit, $onlyParents);
    }

    /**
     * Removes the Role from the registry
     *
     * The $role parameter can either be a Role or a Role identifier.
     *
     * @param  RoleInterface|string $role
     * @uses   RoleRegistry::remove()
     * @return Acl Provides a fluent interface
     */
    public function removeRole($role)
    {
        $this->_getRoleRegistry()->remove($role);

        if ($role instanceof RoleInterface) {
            $roleId = $role->getRoleId();
        } else {
            $roleId = $role;
        }

        foreach ($this->_rules['allResources']['byRoleId'] as $roleIdCurrent => $rules) {
            if ($roleId === $roleIdCurrent) {
                unset($this->_rules['allResources']['byRoleId'][$roleIdCurrent]);
            }
        }
        foreach ($this->_rules['byResourceId'] as $resourceIdCurrent => $visitor) {
            if (array_key_exists('byRoleId', $visitor)) {
                foreach ($visitor['byRoleId'] as $roleIdCurrent => $rules) {
                    if ($roleId === $roleIdCurrent) {
                        unset($this->_rules['byResourceId'][$resourceIdCurrent]['byRoleId'][$roleIdCurrent]);
                    }
                }
            }
        }

        return $this;
    }

    /**
     * Removes all Roles from the registry
     *
     * @uses   RoleRegistry::removeAll()
     * @return Acl Provides a fluent interface
     */
    public function removeRoleAll()
    {
        $this->_getRoleRegistry()->removeAll();

        foreach ($this->_rules['allResources']['byRoleId'] as $roleIdCurrent => $rules) {
            unset($this->_rules['allResources']['byRoleId'][$roleIdCurrent]);
        }
        foreach ($this->_rules['byResourceId'] as $resourceIdCurrent => $visitor) {
            foreach ($visitor['byRoleId'] as $roleIdCurrent => $rules) {
                unset($this->_rules['byResourceId'][$resourceIdCurrent]['byRoleId'][$roleIdCurrent]);
            }
        }

        return $this;
    }

    /**
     * Adds a Resource having an identifier unique to the ACL
     *
     * The $parent parameter may be a reference to, or the string identifier for,
     * the existing Resource from which the newly added Resource will inherit.
     *
     * @param  ResourceInterface|string $resource
     * @param  ResourceInterface|string $parent
     * @throws AmfException
     * @return Acl Provides a fluent interface
     */
    public function addResource($resource, $parent = null)
    {
        if (is_string($resource)) {
            $resource = new Resource($resource);
        }

        if (!$resource instanceof ResourceInterface) {
            throw new AmfException('addResource() expects $resource to be of type ResourceInterface');
        }

        $resourceId = $resource->getResourceId();

        if ($this->has($resourceId)) {
            throw new AmfException("Resource id '$resourceId' already exists in the ACL");
        }

        $resourceParent = null;

        if (null !== $parent) {
            try {
                if ($parent instanceof ResourceInterface) {
                    $resourceParentId = $parent->getResourceId();
                } else {
                    $resourceParentId = $parent;
                }
                $resourceParent = $this->get($resourceParentId);
            } catch (AmfException $e) {
                /** @var $resourceParentId */
                throw new AmfException("Parent Resource id '$resourceParentId' does not exist", 0, $e);
            }
            $this->_resources[$resourceParentId]['children'][$resourceId] = $resource;
        }

        $this->_resources[$resourceId] = array(
            'instance' => $resource,
            'parent'   => $resourceParent,
            'children' => array()
            );

        return $this;
    }

    /**
     * Returns the identified Resource
     *
     * The $resource parameter can either be a Resource or a Resource identifier.
     *
     * @param  ResourceInterface|string $resource
     * @throws AmfException
     * @return ResourceInterface
     */
    public function get($resource)
    {
        if ($resource instanceof ResourceInterface) {
            $resourceId = $resource->getResourceId();
        } else {
            $resourceId = (string) $resource;
        }

        if (!$this->has($resource)) {
            throw new AmfException("Resource '$resourceId' not found");
        }

        return $this->_resources[$resourceId]['instance'];
    }

    /**
     * Returns true if and only if the Resource exists in the ACL
     *
     * The $resource parameter can either be a Resource or a Resource identifier.
     *
     * @param  ResourceInterface|string $resource
     * @return boolean
     */
    public function has($resource)
    {
        if ($resource instanceof ResourceInterface) {
            $resourceId = $resource->getResourceId();
        } else {
            $resourceId = (string) $resource;
        }

        return isset($this->_resources[$resourceId]);
    }

    /**
     * Returns true if and only if $resource inherits from $inherit
     *
     * Both parameters may be either a Resource or a Resource identifier. If
     * $onlyParent is true, then $resource must inherit directly from
     * $inherit in order to return true. By default, this method looks
     * through the entire inheritance tree to determine whether $resource
     * inherits from $inherit through its ancestor Resources.
     *
     * @param  ResourceInterface|string $resource
     * @param  ResourceInterface|string $inherit
     * @param  boolean $onlyParent
     * @return bool
     * @throws AmfException
     */
    public function inherits($resource, $inherit, $onlyParent = false)
    {
        try {
            $resourceId     = $this->get($resource)->getResourceId();
            $inheritId = $this->get($inherit)->getResourceId();
        } catch (AmfException $e) {
            throw new AmfException($e->getMessage(), $e->getCode(), $e);
        }

        if (null !== $this->_resources[$resourceId]['parent']) {
            $parentId = $this->_resources[$resourceId]['parent']->getResourceId();
            if ($inheritId === $parentId) {
                return true;
            } else if ($onlyParent) {
                return false;
            }
        } else {
            return false;
        }

        while (null !== $this->_resources[$parentId]['parent']) {
            $parentId = $this->_resources[$parentId]['parent']->getResourceId();
            if ($inheritId === $parentId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Removes a Resource and all of its children
     *
     * The $resource parameter can either be a Resource or a Resource identifier.
     *
     * @param  ResourceInterface|string $resource
     * @throws AmfException
     * @return Acl Provides a fluent interface
     */
    public function remove($resource)
    {
        try {
            $resourceId = $this->get($resource)->getResourceId();
        } catch (AmfException $e) {
            throw new AmfException($e->getMessage(), $e->getCode(), $e);
        }

        $resourcesRemoved = array($resourceId);
        /** @var ResourceInterface $resourceParent */
        if (null !== ($resourceParent = $this->_resources[$resourceId]['parent'])) {
            unset($this->_resources[$resourceParent->getResourceId()]['children'][$resourceId]);
        }
        foreach ($this->_resources[$resourceId]['children'] as $childId => $child) {
            $this->remove($childId);
            $resourcesRemoved[] = $childId;
        }

        foreach ($resourcesRemoved as $resourceIdRemoved) {
            foreach ($this->_rules['byResourceId'] as $resourceIdCurrent => $rules) {
                if ($resourceIdRemoved === $resourceIdCurrent) {
                    unset($this->_rules['byResourceId'][$resourceIdCurrent]);
                }
            }
        }

        unset($this->_resources[$resourceId]);

        return $this;
    }

    /**
     * Removes all Resources
     *
     * @return Acl Provides a fluent interface
     */
    public function removeAll()
    {
        foreach ($this->_resources as $resourceId => $resource) {
            foreach ($this->_rules['byResourceId'] as $resourceIdCurrent => $rules) {
                if ($resourceId === $resourceIdCurrent) {
                    unset($this->_rules['byResourceId'][$resourceIdCurrent]);
                }
            }
        }

        $this->_resources = [];

        return $this;
    }

    /**
     * Adds an "allow" rule to the ACL
     *
     * @param  RoleInterface|string|array     $roles
     * @param  ResourceInterface|string|array $resources
     * @param  string|array                   $privileges
     * @param  AssertInterface                $assert
     * @uses   Acl::setRule()
     * @return Acl Provides a fluent interface
     */
    public function allow($roles = null, $resources = null, $privileges = null, AssertInterface $assert = null)
    {
        return $this->setRule(self::OP_ADD, self::TYPE_ALLOW, $roles, $resources, $privileges, $assert);
    }

    /**
     * Adds a "deny" rule to the ACL
     *
     * @param  RoleInterface|string|array     $roles
     * @param  ResourceInterface|string|array $resources
     * @param  string|array                             $privileges
     * @param  AssertInterface                $assert
     * @uses   Acl::setRule()
     * @return Acl Provides a fluent interface
     */
    public function deny($roles = null, $resources = null, $privileges = null, AssertInterface $assert = null)
    {
        return $this->setRule(self::OP_ADD, self::TYPE_DENY, $roles, $resources, $privileges, $assert);
    }

    /**
     * Removes "allow" permissions from the ACL
     *
     * @param  RoleInterface|string|array     $roles
     * @param  ResourceInterface|string|array $resources
     * @param  string|array                             $privileges
     * @uses   Acl::setRule()
     * @return Acl Provides a fluent interface
     */
    public function removeAllow($roles = null, $resources = null, $privileges = null)
    {
        return $this->setRule(self::OP_REMOVE, self::TYPE_ALLOW, $roles, $resources, $privileges);
    }

    /**
     * Removes "deny" restrictions from the ACL
     *
     * @param  RoleInterface|string|array     $roles
     * @param  ResourceInterface|string|array $resources
     * @param  string|array                             $privileges
     * @uses   Acl::setRule()
     * @return Acl Provides a fluent interface
     */
    public function removeDeny($roles = null, $resources = null, $privileges = null)
    {
        return $this->setRule(self::OP_REMOVE, self::TYPE_DENY, $roles, $resources, $privileges);
    }

    /**
     * Performs operations on ACL rules
     *
     * The $operation parameter may be either OP_ADD or OP_REMOVE, depending on whether the
     * user wants to add or remove a rule, respectively:
     *
     * OP_ADD specifics:
     *
     *      A rule is added that would allow one or more Roles access to [certain $privileges
     *      upon] the specified Resource(s).
     *
     * OP_REMOVE specifics:
     *
     *      The rule is removed only in the context of the given Roles, Resources, and privileges.
     *      Existing rules to which the remove operation does not apply would remain in the
     *      ACL.
     *
     * The $type parameter may be either TYPE_ALLOW or TYPE_DENY, depending on whether the
     * rule is intended to allow or deny permission, respectively.
     *
     * The $roles and $resources parameters may be references to, or the string identifiers for,
     * existing Resources/Roles, or they may be passed as arrays of these - mixing string identifiers
     * and objects is ok - to indicate the Resources and Roles to which the rule applies. If either
     * $roles or $resources is null, then the rule applies to all Roles or all Resources, respectively.
     * Both may be null in order to work with the default rule of the ACL.
     *
     * The $privileges parameter may be used to further specify that the rule applies only
     * to certain privileges upon the Resource(s) in question. This may be specified to be a single
     * privilege with a string, and multiple privileges may be specified as an array of strings.
     *
     * If $assert is provided, then its assert() method must return true in order for
     * the rule to apply. If $assert is provided with $roles, $resources, and $privileges all
     * equal to null, then a rule having a type of:
     *
     *      TYPE_ALLOW will imply a type of TYPE_DENY, and
     *
     *      TYPE_DENY will imply a type of TYPE_ALLOW
     *
     * when the rule's assertion fails. This is because the ACL needs to provide expected
     * behavior when an assertion upon the default ACL rule fails.
     *
     * @param  string                                   $operation
     * @param  string                                   $type
     * @param  RoleInterface|string|array     $roles
     * @param  ResourceInterface|string|array $resources
     * @param  string|array                             $privileges
     * @param  AssertInterface                $assert
     * @throws AmfException
     * @uses   RoleRegistry::get()
     * @uses   Acl::get()
     * @return Acl Provides a fluent interface
     */
    public function setRule($operation, $type, $roles = null, $resources = null, $privileges = null,
                            AssertInterface $assert = null)
    {
        // ensure that the rule type is valid; normalize input to uppercase
        $type = strtoupper($type);
        if (self::TYPE_ALLOW !== $type && self::TYPE_DENY !== $type) {

            throw new AmfException("Unsupported rule type; must be either '" . self::TYPE_ALLOW . "' or '"
                                       . self::TYPE_DENY . "'");
        }

        // ensure that all specified Roles exist; normalize input to array of Role objects or null
        if (!is_array($roles)) {
            $roles = array($roles);
        } else if (0 === count($roles)) {
            $roles = array(null);
        }
        $rolesTemp = $roles;
        $roles = [];
        foreach ($rolesTemp as $role) {
            if (null !== $role) {
                $roles[] = $this->_getRoleRegistry()->get($role);
            } else {
                $roles[] = null;
            }
        }
        unset($rolesTemp);

        // ensure that all specified Resources exist; normalize input to array of Resource objects or null
        if ($resources !== null) {
            if (!is_array($resources)) {
                $resources = array($resources);
            } else if (0 === count($resources)) {
                $resources = array(null);
            }
            $resourcesTemp = $resources;
            $resources = [];
            foreach ($resourcesTemp as $resource) {
                if (null !== $resource) {
                    $resources[] = $this->get($resource);
                } else {
                    $resources[] = null;
                }
            }
            unset($resourcesTemp, $resource);
        } else {
            $allResources = []; // this might be used later if resource iteration is required
            foreach ($this->_resources as $rTarget) {
                $allResources[] = $rTarget['instance'];
            }
            unset($rTarget);
        }

        // normalize privileges to array
        if (null === $privileges) {
            $privileges = [];
        } else if (!is_array($privileges)) {
            $privileges = array($privileges);
        }

        switch ($operation) {

            // add to the rules
            case self::OP_ADD:
                if ($resources !== null) {
                    // this block will iterate the provided resources
                    foreach ($resources as $resource) {
                        foreach ($roles as $role) {
                            $rules =& $this->_getRules($resource, $role, true);
                            if (0 === count($privileges)) {
                                $rules['allPrivileges']['type']   = $type;
                                $rules['allPrivileges']['assert'] = $assert;
                                if (!isset($rules['byPrivilegeId'])) {
                                    $rules['byPrivilegeId'] = [];
                                }
                            } else {
                                foreach ($privileges as $privilege) {
                                    $rules['byPrivilegeId'][$privilege]['type']   = $type;
                                    $rules['byPrivilegeId'][$privilege]['assert'] = $assert;
                                }
                            }
                        }
                    }
                } else {
                    // this block will apply to all resources in a global rule
                    foreach ($roles as $role) {
                        $rules =& $this->_getRules(null, $role, true);
                        if (0 === count($privileges)) {
                            $rules['allPrivileges']['type']   = $type;
                            $rules['allPrivileges']['assert'] = $assert;
                        } else {
                            foreach ($privileges as $privilege) {
                                $rules['byPrivilegeId'][$privilege]['type']   = $type;
                                $rules['byPrivilegeId'][$privilege]['assert'] = $assert;
                            }
                        }
                    }
                }
                break;

            // remove from the rules
            case self::OP_REMOVE:
                if ($resources !== null) {
                    // this block will iterate the provided resources
                    foreach ($resources as $resource) {
                        foreach ($roles as $role) {
                            $rules =& $this->_getRules($resource, $role);
                            if (null === $rules) {
                                continue;
                            }
                            if (0 === count($privileges)) {
                                if (null === $resource && null === $role) {
                                    if ($type === $rules['allPrivileges']['type']) {
                                        $rules = array(
                                            'allPrivileges' => array(
                                                'type'   => self::TYPE_DENY,
                                                'assert' => null
                                                ),
                                            'byPrivilegeId' => array()
                                            );
                                    }
                                    continue;
                                }

                                if (isset($rules['allPrivileges']['type']) &&
                                    $type === $rules['allPrivileges']['type'])
                                {
                                    unset($rules['allPrivileges']);
                                }
                            } else {
                                foreach ($privileges as $privilege) {
                                    if (isset($rules['byPrivilegeId'][$privilege]) &&
                                        $type === $rules['byPrivilegeId'][$privilege]['type'])
                                    {
                                        unset($rules['byPrivilegeId'][$privilege]);
                                    }
                                }
                            }
                        }
                    }
                } else {
                    // this block will apply to all resources in a global rule
                    foreach ($roles as $role) {
                        /** @var array $allResources */
                        /**
                         * since null (all resources) was passed to this setRule() call, we need
                         * clean up all the rules for the global allResources, as well as the indivually
                         * set resources (per privilege as well)
                         */
                        foreach (array_merge(array(null), $allResources) as $resource) {
                            $rules =& $this->_getRules($resource, $role, true);
                            if (null === $rules) {
                                continue;
                            }
                            if (0 === count($privileges)) {
                                if (null === $role) {
                                    if ($type === $rules['allPrivileges']['type']) {
                                        $rules = array(
                                            'allPrivileges' => array(
                                                'type'   => self::TYPE_DENY,
                                                'assert' => null
                                                ),
                                            'byPrivilegeId' => array()
                                            );
                                    }
                                    continue;
                                }

                                if (isset($rules['allPrivileges']['type']) && $type === $rules['allPrivileges']['type']) {
                                    unset($rules['allPrivileges']);
                                }
                            } else {
                                foreach ($privileges as $privilege) {
                                    if (isset($rules['byPrivilegeId'][$privilege]) &&
                                        $type === $rules['byPrivilegeId'][$privilege]['type'])
                                    {
                                        unset($rules['byPrivilegeId'][$privilege]);
                                    }
                                }
                            }
                        }
                    }
                }
                break;

            default:

                throw new AmfException("Unsupported operation; must be either '" . self::OP_ADD . "' or '"
                                           . self::OP_REMOVE . "'");
        }

        return $this;
    }

    /**
     * Returns true if and only if the Role has access to the Resource
     *
     * The $role and $resource parameters may be references to, or the string identifiers for,
     * an existing Resource and Role combination.
     *
     * If either $role or $resource is null, then the query applies to all Roles or all Resources,
     * respectively. Both may be null to query whether the ACL has a "blacklist" rule
     * (allow everything to all). By default, Acl creates a "whitelist" rule (deny
     * everything to all), and this method would return false unless this default has
     * been overridden (i.e., by executing $acl->allow()).
     *
     * If a $privilege is not provided, then this method returns false if and only if the
     * Role is denied access to at least one privilege upon the Resource. In other words, this
     * method returns true if and only if the Role is allowed all privileges on the Resource.
     *
     * This method checks Role inheritance using a depth-first traversal of the Role registry.
     * The highest priority parent (i.e., the parent most recently added) is checked first,
     * and its respective parents are checked similarly before the lower-priority parents of
     * the Role are checked.
     *
     * @param  RoleInterface|string     $role
     * @param  ResourceInterface|string $resource
     * @param  string                             $privilege
     * @uses   Acl::get()
     * @uses   RoleRegistry::get()
     * @return boolean
     */
    public function isAllowed($role = null, $resource = null, $privilege = null)
    {
        // reset role & resource to null
        $this->_isAllowedRole = null;
        $this->_isAllowedResource = null;
        $this->_isAllowedPrivilege = null;

        if (null !== $role) {
            // keep track of originally called role
            $this->_isAllowedRole = $role;
            $role = $this->_getRoleRegistry()->get($role);
            if (!$this->_isAllowedRole instanceof RoleInterface) {
                $this->_isAllowedRole = $role;
            }
        }

        if (null !== $resource) {
            // keep track of originally called resource
            $this->_isAllowedResource = $resource;
            $resource = $this->get($resource);
            if (!$this->_isAllowedResource instanceof ResourceInterface) {
                $this->_isAllowedResource = $resource;
            }
        }

        if (null === $privilege) {
            // query on all privileges
            do {
                // depth-first search on $role if it is not 'allRoles' pseudo-parent
                if (null !== $role && null !== ($result = $this->_roleDFSAllPrivileges($role, $resource))) {
                    return $result;
                }

                // look for rule on 'allRoles' psuedo-parent
                if (null !== ($rules = $this->_getRules($resource, null))) {
                    foreach ($rules['byPrivilegeId'] as $privilege => $rule) {
                        if (self::TYPE_DENY === ($ruleTypeOnePrivilege = $this->_getRuleType($resource, null, $privilege))) {
                            return false;
                        }
                    }
                    if (null !== ($ruleTypeAllPrivileges = $this->_getRuleType($resource, null, null))) {
                        return self::TYPE_ALLOW === $ruleTypeAllPrivileges;
                    }
                }

                // try next Resource
                $resource = $this->_resources[$resource->getResourceId()]['parent'];

            } while (true); // loop terminates at 'allResources' pseudo-parent
        } else {
            $this->_isAllowedPrivilege = $privilege;
            // query on one privilege
            do {
                // depth-first search on $role if it is not 'allRoles' pseudo-parent
                if (null !== $role && null !== ($result = $this->_roleDFSOnePrivilege($role, $resource, $privilege))) {
                    return $result;
                }

                // look for rule on 'allRoles' pseudo-parent
                if (null !== ($ruleType = $this->_getRuleType($resource, null, $privilege))) {
                    return self::TYPE_ALLOW === $ruleType;
                } else if (null !== ($ruleTypeAllPrivileges = $this->_getRuleType($resource, null, null))) {
                    return self::TYPE_ALLOW === $ruleTypeAllPrivileges;
                }

                // try next Resource
                $resource = $this->_resources[$resource->getResourceId()]['parent'];

            } while (true); // loop terminates at 'allResources' pseudo-parent
        }

        return false;
    }

    /**
     * Returns the Role registry for this ACL
     *
     * If no Role registry has been created yet, a new default Role registry
     * is created and returned.
     *
     * @return RoleRegistry
     */
    protected function _getRoleRegistry()
    {
        if (null === $this->_roleRegistry) {
            $this->_roleRegistry = new RoleRegistry();
        }
        return $this->_roleRegistry;
    }

    /**
     * Performs a depth-first search of the Role DAG, starting at $role, in order to find a rule
     * allowing/denying $role access to all privileges upon $resource
     *
     * This method returns true if a rule is found and allows access. If a rule exists and denies access,
     * then this method returns false. If no applicable rule is found, then this method returns null.
     *
     * @param  RoleInterface     $role
     * @param  ResourceInterface $resource
     * @return boolean|null
     */
    protected function _roleDFSAllPrivileges(RoleInterface $role, ResourceInterface $resource = null)
    {
        $dfs = array(
            'visited' => array(),
            'stack'   => array()
            );

        if (null !== ($result = $this->_roleDFSVisitAllPrivileges($role, $resource, $dfs))) {
            return $result;
        }

        while (null !== ($role = array_pop($dfs['stack']))) {
            if (!isset($dfs['visited'][$role->getRoleId()])) {
                if (null !== ($result = $this->_roleDFSVisitAllPrivileges($role, $resource, $dfs))) {
                    return $result;
                }
            }
        }

        return null;
    }

    /**
     * Visits an $role in order to look for a rule allowing/denying $role access to all privileges upon $resource
     *
     * This method returns true if a rule is found and allows access. If a rule exists and denies access,
     * then this method returns false. If no applicable rule is found, then this method returns null.
     *
     * This method is used by the internal depth-first search algorithm and may modify the DFS data structure.
     *
     * @param  RoleInterface     $role
     * @param  ResourceInterface $resource
     * @param  array                  $dfs
     * @return boolean|null
     * @throws AmfException
     */
    protected function _roleDFSVisitAllPrivileges(RoleInterface $role, ResourceInterface $resource = null,
                                                 &$dfs = null)
    {
        if (null === $dfs) {
            throw new AmfException('$dfs parameter may not be null');
        }

        if (null !== ($rules = $this->_getRules($resource, $role))) {
            foreach ($rules['byPrivilegeId'] as $privilege => $rule) {
                if (self::TYPE_DENY === ($ruleTypeOnePrivilege = $this->_getRuleType($resource, $role, $privilege))) {
                    return false;
                }
            }
            if (null !== ($ruleTypeAllPrivileges = $this->_getRuleType($resource, $role, null))) {
                return self::TYPE_ALLOW === $ruleTypeAllPrivileges;
            }
        }

        $dfs['visited'][$role->getRoleId()] = true;
        foreach ($this->_getRoleRegistry()->getParents($role) as $roleParentId => $roleParent) {
            $dfs['stack'][] = $roleParent;
        }

        return null;
    }

    /**
     * Performs a depth-first search of the Role DAG, starting at $role, in order to find a rule
     * allowing/denying $role access to a $privilege upon $resource
     *
     * This method returns true if a rule is found and allows access. If a rule exists and denies access,
     * then this method returns false. If no applicable rule is found, then this method returns null.
     *
     * @param  RoleInterface     $role
     * @param  ResourceInterface $resource
     * @param  string                      $privilege
     * @return boolean|null
     * @throws AmfException
     */
    protected function _roleDFSOnePrivilege(RoleInterface $role, ResourceInterface $resource = null,
                                            $privilege = null)
    {
        if (null === $privilege) {
            throw new AmfException('$privilege parameter may not be null');
        }

        $dfs = array(
            'visited' => array(),
            'stack'   => array()
            );

        if (null !== ($result = $this->_roleDFSVisitOnePrivilege($role, $resource, $privilege, $dfs))) {
            return $result;
        }

        while (null !== ($role = array_pop($dfs['stack']))) {
            if (!isset($dfs['visited'][$role->getRoleId()])) {
                if (null !== ($result = $this->_roleDFSVisitOnePrivilege($role, $resource, $privilege, $dfs))) {
                    return $result;
                }
            }
        }

        return null;
    }

    /**
     * Visits an $role in order to look for a rule allowing/denying $role access to a $privilege upon $resource
     *
     * This method returns true if a rule is found and allows access. If a rule exists and denies access,
     * then this method returns false. If no applicable rule is found, then this method returns null.
     *
     * This method is used by the internal depth-first search algorithm and may modify the DFS data structure.
     *
     * @param  RoleInterface     $role
     * @param  ResourceInterface $resource
     * @param  string                      $privilege
     * @param  array                       $dfs
     * @return boolean|null
     * @throws AmfException
     */
    protected function _roleDFSVisitOnePrivilege(RoleInterface $role, ResourceInterface $resource = null,
                                                $privilege = null, &$dfs = null)
    {
        if (null === $privilege) {
            throw new AmfException('$privilege parameter may not be null');
        }

        if (null === $dfs) {
            throw new AmfException('$dfs parameter may not be null');
        }

        if (null !== ($ruleTypeOnePrivilege = $this->_getRuleType($resource, $role, $privilege))) {
            return self::TYPE_ALLOW === $ruleTypeOnePrivilege;
        } else if (null !== ($ruleTypeAllPrivileges = $this->_getRuleType($resource, $role, null))) {
            return self::TYPE_ALLOW === $ruleTypeAllPrivileges;
        }

        $dfs['visited'][$role->getRoleId()] = true;
        foreach ($this->_getRoleRegistry()->getParents($role) as $roleParentId => $roleParent) {
            $dfs['stack'][] = $roleParent;
        }

        return null;
    }

    /**
     * Returns the rule type associated with the specified Resource, Role, and privilege
     * combination.
     *
     * If a rule does not exist or its attached assertion fails, which means that
     * the rule is not applicable, then this method returns null. Otherwise, the
     * rule type applies and is returned as either TYPE_ALLOW or TYPE_DENY.
     *
     * If $resource or $role is null, then this means that the rule must apply to
     * all Resources or Roles, respectively.
     *
     * If $privilege is null, then the rule must apply to all privileges.
     *
     * If all three parameters are null, then the default ACL rule type is returned,
     * based on whether its assertion method passes.
     *
     * @param  ResourceInterface $resource
     * @param  RoleInterface     $role
     * @param  string                      $privilege
     * @return string|null
     */
    protected function _getRuleType(ResourceInterface $resource = null, RoleInterface $role = null,
                                    $privilege = null)
    {
        // get the rules for the $resource and $role
        if (null === ($rules = $this->_getRules($resource, $role))) {
            return null;
        }

        // follow $privilege
        if (null === $privilege) {
            if (isset($rules['allPrivileges'])) {
                $rule = $rules['allPrivileges'];
            } else {
                return null;
            }
        } else if (!isset($rules['byPrivilegeId'][$privilege])) {
            return null;
        } else {
            $rule = $rules['byPrivilegeId'][$privilege];
        }

        // check assertion first
        if ($rule['assert']) {
            /** @var AssertInterface $assertion */
            $assertion = $rule['assert'];
            $assertionValue = $assertion->assert(
                $this,
                ($this->_isAllowedRole instanceof RoleInterface) ? $this->_isAllowedRole : $role,
                ($this->_isAllowedResource instanceof ResourceInterface) ? $this->_isAllowedResource : $resource,
                $this->_isAllowedPrivilege
                );
        }
        else
        {
            $assertionValue = false;
        }

        if (null === $rule['assert'] || $assertionValue) {
            return $rule['type'];
        } else if (null !== $resource || null !== $role || null !== $privilege) {
            return null;
        } else if (self::TYPE_ALLOW === $rule['type']) {
            return self::TYPE_DENY;
        } else {
            return self::TYPE_ALLOW;
        }
    }

    /**
     * Returns the rules associated with a Resource and a Role, or null if no such rules exist
     *
     * If either $resource or $role is null, this means that the rules returned are for all Resources or all Roles,
     * respectively. Both can be null to return the default rule set for all Resources and all Roles.
     *
     * If the $create parameter is true, then a rule set is first created and then returned to the caller.
     *
     * @param  ResourceInterface $resource
     * @param  RoleInterface     $role
     * @param  boolean                     $create
     * @return array|null
     */
    protected function &_getRules(ResourceInterface $resource = null, RoleInterface $role = null,
                                  $create = false)
    {
        // create a reference to null
        $null = null;
        $nullRef =& $null;

        // follow $resource
        do {
            if (null === $resource) {
                $visitor =& $this->_rules['allResources'];
                break;
            }
            $resourceId = $resource->getResourceId();
            if (!isset($this->_rules['byResourceId'][$resourceId])) {
                if (!$create) {
                    return $nullRef;
                }
                $this->_rules['byResourceId'][$resourceId] = [];
            }
            $visitor =& $this->_rules['byResourceId'][$resourceId];
        } while (false);


        // follow $role
        if (null === $role) {
            if (!isset($visitor['allRoles'])) {
                if (!$create) {
                    return $nullRef;
                }
                $visitor['allRoles']['byPrivilegeId'] = [];
            }
            return $visitor['allRoles'];
        }
        $roleId = $role->getRoleId();
        if (!isset($visitor['byRoleId'][$roleId])) {
            if (!$create) {
                return $nullRef;
            }
            $visitor['byRoleId'][$roleId]['byPrivilegeId'] = [];
            $visitor['byRoleId'][$roleId]['allPrivileges'] = array('type' => null, 'assert' => null);
        }
        return $visitor['byRoleId'][$roleId];
    }


    /**
     * Returns an array of registered roles.
     *
     * Note that this method does not return instances of registered roles,
     * but only the role identifiers.
     *
     * @return array of registered roles
     */
    public function getRoles()
    {
        return array_keys($this->_getRoleRegistry()->getRoles());
    }

    /**
     * @return array of registered resources
     */
    public function getResources()
    {
        return array_keys($this->_resources);
    }

}

