<?php
/**
 * CActiveRecord class file.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @modified Charles R. Portwood II <charlesportwoodii@ethreal.net>
 * @link http://www.yiiframework.com/
 * @copyright Copyright &copy; 2008-2011 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */
 
/**
 * CDynamicRecord is a dynamic ActiveRecord class that, upon instantiation creates a dynamic database connection to a specified world
 * CDynamicRecord can be called by both the following definitions
 * $model = new CDynamicRecord($dbConnectionString)
 * $data  = CDynamicRecord::model($dbConnectionString)->method()->param
 * 
 * This class has to fully reimplement ActiveRecord so that everything works. I never was able to figure out why Class Inheritance wouldn't work after I changed model()
 * @See CActiveRcord
 */
class CDynamicRecord extends CActiveRecord
{
    /**
     * @var mixed $dbConnectionString
     * The connection string details we want to override
     */
    public $dbConnectionString;
    
    // Static properties HAVE to be redeclared. Weird Yii Bug
    const BELONGS_TO='CBelongsToRelation';
    const HAS_ONE='CHasOneRelation';
    const HAS_MANY='CHasManyRelation';
    const MANY_MANY='CManyManyRelation';
    const STAT='CStatRelation';

    /**
     * @var CDbConnection the default database connection for all active record classes.
     * By default, this is the 'db' application component.
     * @see getDbConnection
     */
    public static $db;

    private static $_models=array();            // class name => model

    private $_md;                               // meta data
    private $_new=false;                        // whether this instance is new or not
    private $_attributes=array();               // attribute name => attribute value
    private $_related=array();                  // attribute name => related objects
    private $_c;                                // query criteria (used by finder only)
    private $_pk;                               // old primary key value
    private $_alias='t';                        // the table alias being used for query
    
    /**
     * This method allows us to customize how we want our connection string to be manipulated
     * It takes the value of CiiModelDynamic::$dbConnectionString for manipulation
     * @return true
     */
    private function overrideDbConnection()
    {
        Yii::app()->db->setActive(false);
        Yii::app()->db->connectionString = str_replace('base', $this->dbConnectionString, Yii::app()->db->connectionString);
        Yii::app()->db->setActive(true);
        
        return true;
    }
    
    /**
     * This method is overwritten to allow for $this->getMetaData to pass in our connectionString
     * @see CActiveRecord::__get($name)
     */
    public function __get($name)
    {
        if(isset($this->_attributes[$name]))
            return $this->_attributes[$name];
        elseif(isset($this->getMetaData($this->dbConnectionString)->columns[$name]))
            return null;
        elseif(isset($this->_related[$name]))
            return $this->_related[$name];
        elseif(isset($this->getMetaData($this->dbConnectionString)->relations[$name]))
            return $this->getRelated($name);
        else
            return parent::__get($name);
    }
    
    /**
     * Our base constructor now takes into account the current world and allows us to override the database connection
     * When calling CiiModelDynamic you have to call as follows
     * $model = new CiiModelDynamic($dbConnectionStringNumber);
     * @param int    $dbConnectionString     The connection string we want to implement
     * @param string $scenario  Yii's Scenario
     * 
     * @see CActiveRecord::__construct($scenario='insert');
     */
    public function __construct($dbConnectionString = NULL, $scenario='insert')
    {
        if ($dbConnectionString == NULL)
            throw new CException('CiiModelDynamic requires that a world be set before instantiation');

        $this->dbConnectionString = $dbConnectionString;
        
        $this->overrideDbConnection();
        
        if($scenario===null) // internally used by populateRecord() and model()
            return;

        $this->setScenario($scenario);
        $this->setIsNewRecord(true);
        $this->_attributes=$this->getMetaData($dbConnectionString)->attributeDefaults;
    
        $this->init();
    
        $this->attachBehaviors($this->behaviors());
        $this->afterConstruct();
    }
    
    /**
     * This class allows us to instantiate for CiiModelDynamic::model($dbConnectionString)->method()->param
     * @param mxied $attributes     Yii Attributes
     * 
     * @see CActiveRecord::instantiate($attributes)
     */
    protected function instantiate($attributes)
    {
        $class=get_class($this);
        $model=new $class($this->dbConnectionString, null);
        return $model;
    }
    
    /**
     * Overwritetn to support the $dbConnectionString param
     * @param int $dbConnectionString        The connection string we want to implement
     * @see CActiveRecord::getMetaData();
     */
    public function getMetaData($dbConnectionString=0)
    {
        if($this->_md!==null)
            return $this->_md;
        else
            return $this->_md=self::model($dbConnectionString, get_class($this))->_md;
    }
    
    /**
     * This method has been overwritten so that the following call can be made
     * Each class that extends CiiModelDynamic MUST implement this method definition
     * rather than the one prepopulated by Gii. If this method isn't overwritten
     * in the child ARModel, it will assume that CiiModelDynamic (explicit text there)
     * is intended to be used. CiiModelDynamic won't have a table mapping so it will
     * nasty errors otherwise.
     * 
     * The pretty part about this is that you can now do this with CiiModelDynamic
     * CiiModelDynamic::model($dbConnectionString)->method()->param
     * 
     * @param int   $dbConnectionString      The connection string we want to implement
     * @param obj   $className               The class name we want to use
     * 
     * @see CActiveRecord::model($className=__CLASS__)
     */
    public static function model($dbConnectionString = 0, $className=__CLASS__)
    {
        if ($dbConnectionString === NULL)
            throw new CException('CiiModelDynamic requires that a world be set before instantiation');
        
        if(isset(self::$_models[$className]))
            return self::$_models[$className];
        else
        {
            $model=self::$_models[$className]=new $className($dbConnectionString, null);
            $model->world = $dbConnectionString;
            $model->_md=new CActiveRecordMetaData($model);
            $model->attachBehaviors($model->behaviors());
            return $model;
        }
    } 
    /**
     * Sets the parameters about query caching.
     * This is a shortcut method to {@link CDbConnection::cache()}.
     * It changes the query caching parameter of the {@link dbConnection} instance.
     * @param integer $duration the number of seconds that query results may remain valid in cache.
     * If this is 0, the caching will be disabled.
     * @param CCacheDependency $dependency the dependency that will be used when saving the query results into cache.
     * @param integer $queryCount number of SQL queries that need to be cached after calling this method. Defaults to 1,
     * meaning that the next SQL query will be cached.
     * @return CActiveRecord the active record instance itself.
     * @since 1.1.7
     */
    public function cache($duration, $dependency=null, $queryCount=1)
    {
        $this->getDbConnection()->cache($duration, $dependency, $queryCount);
        return $this;
    }

    /**
     * PHP sleep magic method.
     * This method ensures that the model meta data reference is set to null.
     * @return array
     */
    public function __sleep()
    {
        $this->_md=null;
        return array_keys((array)$this);
    }

    /**
     * PHP setter magic method.
     * This method is overridden so that AR attributes can be accessed like properties.
     * @param string $name property name
     * @param mixed $value property value
     */
    public function __set($name,$value)
    {
        if($this->setAttribute($name,$value)===false)
        {
            if(isset($this->getMetaData()->relations[$name]))
                $this->_related[$name]=$value;
            else
                parent::__set($name,$value);
        }
    }

    /**
     * Checks if a property value is null.
     * This method overrides the parent implementation by checking
     * if the named attribute is null or not.
     * @param string $name the property name or the event name
     * @return boolean whether the property value is null
     */
    public function __isset($name)
    {
        if(isset($this->_attributes[$name]))
            return true;
        elseif(isset($this->getMetaData()->columns[$name]))
            return false;
        elseif(isset($this->_related[$name]))
            return true;
        elseif(isset($this->getMetaData()->relations[$name]))
            return $this->getRelated($name)!==null;
        else
            return parent::__isset($name);
    }

    /**
     * Sets a component property to be null.
     * This method overrides the parent implementation by clearing
     * the specified attribute value.
     * @param string $name the property name or the event name
     */
    public function __unset($name)
    {
        if(isset($this->getMetaData()->columns[$name]))
            unset($this->_attributes[$name]);
        elseif(isset($this->getMetaData()->relations[$name]))
            unset($this->_related[$name]);
        else
            parent::__unset($name);
    }

    /**
     * Calls the named method which is not a class method.
     * Do not call this method. This is a PHP magic method that we override
     * to implement the named scope feature.
     * @param string $name the method name
     * @param array $parameters method parameters
     * @return mixed the method return value
     */
    public function __call($name,$parameters)
    {
        if(isset($this->getMetaData()->relations[$name]))
        {
            if(empty($parameters))
                return $this->getRelated($name,false);
            else
                return $this->getRelated($name,false,$parameters[0]);
        }

        $scopes=$this->scopes();
        if(isset($scopes[$name]))
        {
            $this->getDbCriteria()->mergeWith($scopes[$name]);
            return $this;
        }

        return parent::__call($name,$parameters);
    }

    /**
     * Returns the related record(s).
     * This method will return the related record(s) of the current record.
     * If the relation is HAS_ONE or BELONGS_TO, it will return a single object
     * or null if the object does not exist.
     * If the relation is HAS_MANY or MANY_MANY, it will return an array of objects
     * or an empty array.
     * @param string $name the relation name (see {@link relations})
     * @param boolean $refresh whether to reload the related objects from database. Defaults to false.
     * @param mixed $params array or CDbCriteria object with additional parameters that customize the query conditions as specified in the relation declaration.
     * @return mixed the related object(s).
     * @throws CDbException if the relation is not specified in {@link relations}.
     */
    public function getRelated($name,$refresh=false,$params=array())
    {
        if(!$refresh && $params===array() && (isset($this->_related[$name]) || array_key_exists($name,$this->_related)))
            return $this->_related[$name];

        $md=$this->getMetaData();
        if(!isset($md->relations[$name]))
            throw new CDbException(Yii::t('yii','{class} does not have relation "{name}".',
                array('{class}'=>get_class($this), '{name}'=>$name)));

        Yii::trace('lazy loading '.get_class($this).'.'.$name,'system.db.ar.CActiveRecord');
        $relation=$md->relations[$name];
        if($this->getIsNewRecord() && !$refresh && ($relation instanceof CHasOneRelation || $relation instanceof CHasManyRelation))
            return $relation instanceof CHasOneRelation ? null : array();

        if($params!==array()) // dynamic query
        {
            $exists=isset($this->_related[$name]) || array_key_exists($name,$this->_related);
            if($exists)
                $save=$this->_related[$name];

            if($params instanceof CDbCriteria)
                $params = $params->toArray();

            $r=array($name=>$params);
        }
        else
            $r=$name;
        unset($this->_related[$name]);

        $finder=new CActiveFinder($this,$r);
        $finder->lazyFind($this);

        if(!isset($this->_related[$name]))
        {
            if($relation instanceof CHasManyRelation)
                $this->_related[$name]=array();
            elseif($relation instanceof CStatRelation)
                $this->_related[$name]=$relation->defaultValue;
            else
                $this->_related[$name]=null;
        }

        if($params!==array())
        {
            $results=$this->_related[$name];
            if($exists)
                $this->_related[$name]=$save;
            else
                unset($this->_related[$name]);
            return $results;
        }
        else
            return $this->_related[$name];
    }

    /**
     * Returns a value indicating whether the named related object(s) has been loaded.
     * @param string $name the relation name
     * @return boolean a value indicating whether the named related object(s) has been loaded.
     */
    public function hasRelated($name)
    {
        return isset($this->_related[$name]) || array_key_exists($name,$this->_related);
    }

    /**
     * Returns the query criteria associated with this model.
     * @param boolean $createIfNull whether to create a criteria instance if it does not exist. Defaults to true.
     * @return CDbCriteria the query criteria that is associated with this model.
     * This criteria is mainly used by {@link scopes named scope} feature to accumulate
     * different criteria specifications.
     */
    public function getDbCriteria($createIfNull=true)
    {
        if($this->_c===null)
        {
            if(($c=$this->defaultScope())!==array() || $createIfNull)
                $this->_c=new CDbCriteria($c);
        }
        return $this->_c;
    }

    /**
     * Sets the query criteria for the current model.
     * @param CDbCriteria $criteria the query criteria
     * @since 1.1.3
     */
    public function setDbCriteria($criteria)
    {
        $this->_c=$criteria;
    }

    /**
     * Returns the default named scope that should be implicitly applied to all queries for this model.
     * Note, default scope only applies to SELECT queries. It is ignored for INSERT, UPDATE and DELETE queries.
     * The default implementation simply returns an empty array. You may override this method
     * if the model needs to be queried with some default criteria (e.g. only active records should be returned).
     * @return array the query criteria. This will be used as the parameter to the constructor
     * of {@link CDbCriteria}.
     */
    public function defaultScope()
    {
        return array();
    }

    /**
     * Resets all scopes and criterias applied.
     *
     * @param boolean $resetDefault including default scope. This parameter available since 1.1.12
     * @return CActiveRecord
     * @since 1.1.2
     */
    public function resetScope($resetDefault=true)
    {
        if($resetDefault)
            $this->_c=new CDbCriteria();
        else
            $this->_c=null;

        return $this;
    }

    /**
     * Refreshes the meta data for this AR class.
     * By calling this method, this AR class will regenerate the meta data needed.
     * This is useful if the table schema has been changed and you want to use the latest
     * available table schema. Make sure you have called {@link CDbSchema::refresh}
     * before you call this method. Otherwise, old table schema data will still be used.
     */
    public function refreshMetaData()
    {
        $finder=self::model(get_class($this));
        $finder->_md=new CActiveRecordMetaData($finder);
        if($this!==$finder)
            $this->_md=$finder->_md;
    }

    /**
     * Returns the name of the associated database table.
     * By default this method returns the class name as the table name.
     * You may override this method if the table is not named after this convention.
     * @return string the table name
     */
    public function tableName()
    {
        return get_class($this);
    }

    /**
     * Returns the primary key of the associated database table.
     * This method is meant to be overridden in case when the table is not defined with a primary key
     * (for some legency database). If the table is already defined with a primary key,
     * you do not need to override this method. The default implementation simply returns null,
     * meaning using the primary key defined in the database.
     * @return mixed the primary key of the associated database table.
     * If the key is a single column, it should return the column name;
     * If the key is a composite one consisting of several columns, it should
     * return the array of the key column names.
     */
    public function primaryKey()
    {
    }

    /**
     * This method should be overridden to declare related objects.
     *
     * There are four types of relations that may exist between two active record objects:
     * <ul>
     * <li>BELONGS_TO: e.g. a member belongs to a team;</li>
     * <li>HAS_ONE: e.g. a member has at most one profile;</li>
     * <li>HAS_MANY: e.g. a team has many members;</li>
     * <li>MANY_MANY: e.g. a member has many skills and a skill belongs to a member.</li>
     * </ul>
     *
     * Besides the above relation types, a special relation called STAT is also supported
     * that can be used to perform statistical query (or aggregational query).
     * It retrieves the aggregational information about the related objects, such as the number
     * of comments for each post, the average rating for each product, etc.
     *
     * Each kind of related objects is defined in this method as an array with the following elements:
     * <pre>
     * 'varName'=>array('relationType', 'className', 'foreignKey', ...additional options)
     * </pre>
     * where 'varName' refers to the name of the variable/property that the related object(s) can
     * be accessed through; 'relationType' refers to the type of the relation, which can be one of the
     * following four constants: self::BELONGS_TO, self::HAS_ONE, self::HAS_MANY and self::MANY_MANY;
     * 'className' refers to the name of the active record class that the related object(s) is of;
     * and 'foreignKey' states the foreign key that relates the two kinds of active record.
     * Note, for composite foreign keys, they can be either listed together, separated by commas or specified as an array
     * in format of array('key1','key2'). In case you need to specify custom PK->FK association you can define it as
     * array('fk'=>'pk'). For composite keys it will be array('fk_c1'=>'pk_с1','fk_c2'=>'pk_c2').
     * For foreign keys used in MANY_MANY relation, the joining table must be declared as well
     * (e.g. 'join_table(fk1, fk2)').
     *
     * Additional options may be specified as name-value pairs in the rest array elements:
     * <ul>
     * <li>'select': string|array, a list of columns to be selected. Defaults to '*', meaning all columns.
     *   Column names should be disambiguated if they appear in an expression (e.g. COUNT(relationName.name) AS name_count).</li>
     * <li>'condition': string, the WHERE clause. Defaults to empty. Note, column references need to
     *   be disambiguated with prefix 'relationName.' (e.g. relationName.age&gt;20)</li>
     * <li>'order': string, the ORDER BY clause. Defaults to empty. Note, column references need to
     *   be disambiguated with prefix 'relationName.' (e.g. relationName.age DESC)</li>
     * <li>'with': string|array, a list of child related objects that should be loaded together with this object.
     *   Note, this is only honored by lazy loading, not eager loading.</li>
     * <li>'joinType': type of join. Defaults to 'LEFT OUTER JOIN'.</li>
     * <li>'alias': the alias for the table associated with this relationship.
     *   It defaults to null,
     *   meaning the table alias is the same as the relation name.</li>
     * <li>'params': the parameters to be bound to the generated SQL statement.
     *   This should be given as an array of name-value pairs.</li>
     * <li>'on': the ON clause. The condition specified here will be appended
     *   to the joining condition using the AND operator.</li>
     * <li>'index': the name of the column whose values should be used as keys
     *   of the array that stores related objects. This option is only available to
     *   HAS_MANY and MANY_MANY relations.</li>
     * <li>'scopes': scopes to apply. In case of a single scope can be used like 'scopes'=>'scopeName',
     *   in case of multiple scopes can be used like 'scopes'=>array('scopeName1','scopeName2').
     *   This option has been available since version 1.1.9.</li>
     * </ul>
     *
     * The following options are available for certain relations when lazy loading:
     * <ul>
     * <li>'group': string, the GROUP BY clause. Defaults to empty. Note, column references need to
     *   be disambiguated with prefix 'relationName.' (e.g. relationName.age). This option only applies to HAS_MANY and MANY_MANY relations.</li>
     * <li>'having': string, the HAVING clause. Defaults to empty. Note, column references need to
     *   be disambiguated with prefix 'relationName.' (e.g. relationName.age). This option only applies to HAS_MANY and MANY_MANY relations.</li>
     * <li>'limit': limit of the rows to be selected. This option does not apply to BELONGS_TO relation.</li>
     * <li>'offset': offset of the rows to be selected. This option does not apply to BELONGS_TO relation.</li>
     * <li>'through': name of the model's relation that will be used as a bridge when getting related data. Can be set only for HAS_ONE and HAS_MANY. This option has been available since version 1.1.7.</li>
     * </ul>
     *
     * Below is an example declaring related objects for 'Post' active record class:
     * <pre>
     * return array(
     *     'author'=>array(self::BELONGS_TO, 'User', 'author_id'),
     *     'comments'=>array(self::HAS_MANY, 'Comment', 'post_id', 'with'=>'author', 'order'=>'create_time DESC'),
     *     'tags'=>array(self::MANY_MANY, 'Tag', 'post_tag(post_id, tag_id)', 'order'=>'name'),
     * );
     * </pre>
     *
     * @return array list of related object declarations. Defaults to empty array.
     */
    public function relations()
    {
        return array();
    }

    /**
     * Returns the declaration of named scopes.
     * A named scope represents a query criteria that can be chained together with
     * other named scopes and applied to a query. This method should be overridden
     * by child classes to declare named scopes for the particular AR classes.
     * For example, the following code declares two named scopes: 'recently' and
     * 'published'.
     * <pre>
     * return array(
     *     'published'=>array(
     *           'condition'=>'status=1',
     *     ),
     *     'recently'=>array(
     *           'order'=>'create_time DESC',
     *           'limit'=>5,
     *     ),
     * );
     * </pre>
     * If the above scopes are declared in a 'Post' model, we can perform the following
     * queries:
     * <pre>
     * $posts=Post::model()->published()->findAll();
     * $posts=Post::model()->published()->recently()->findAll();
     * $posts=Post::model()->published()->with('comments')->findAll();
     * </pre>
     * Note that the last query is a relational query.
     *
     * @return array the scope definition. The array keys are scope names; the array
     * values are the corresponding scope definitions. Each scope definition is represented
     * as an array whose keys must be properties of {@link CDbCriteria}.
     */
    public function scopes()
    {
        return array();
    }

    /**
     * Returns the list of all attribute names of the model.
     * This would return all column names of the table associated with this AR class.
     * @return array list of attribute names.
     */
    public function attributeNames()
    {
        return array_keys($this->getMetaData()->columns);
    }

    /**
     * Returns the text label for the specified attribute.
     * This method overrides the parent implementation by supporting
     * returning the label defined in relational object.
     * In particular, if the attribute name is in the form of "post.author.name",
     * then this method will derive the label from the "author" relation's "name" attribute.
     * @param string $attribute the attribute name
     * @return string the attribute label
     * @see generateAttributeLabel
     * @since 1.1.4
     */
    public function getAttributeLabel($attribute)
    {
        $labels=$this->attributeLabels();
        if(isset($labels[$attribute]))
            return $labels[$attribute];
        elseif(strpos($attribute,'.')!==false)
        {
            $segs=explode('.',$attribute);
            $name=array_pop($segs);
            $model=$this;
            foreach($segs as $seg)
            {
                $relations=$model->getMetaData()->relations;
                if(isset($relations[$seg]))
                    $model=CActiveRecord::model($relations[$seg]->className);
                else
                    break;
            }
            return $model->getAttributeLabel($name);
        }
        else
            return $this->generateAttributeLabel($attribute);
    }

    /**
     * Returns the database connection used by active record.
     * By default, the "db" application component is used as the database connection.
     * You may override this method if you want to use a different database connection.
     * @return CDbConnection the database connection used by active record.
     */
    public function getDbConnection()
    {
        if(self::$db!==null)
            return self::$db;
        else
        {
            self::$db=Yii::app()->getDb();
            if(self::$db instanceof CDbConnection)
                return self::$db;
            else
                throw new CDbException(Yii::t('yii','Active Record requires a "db" CDbConnection application component.'));
        }
    }

    /**
     * Returns the named relation declared for this AR class.
     * @param string $name the relation name
     * @return CActiveRelation the named relation declared for this AR class. Null if the relation does not exist.
     */
    public function getActiveRelation($name)
    {
        return isset($this->getMetaData()->relations[$name]) ? $this->getMetaData()->relations[$name] : null;
    }

    /**
     * Returns the metadata of the table that this AR belongs to
     * @return CDbTableSchema the metadata of the table that this AR belongs to
     */
    public function getTableSchema()
    {
        return $this->getMetaData()->tableSchema;
    }

    /**
     * Returns the command builder used by this AR.
     * @return CDbCommandBuilder the command builder used by this AR
     */
    public function getCommandBuilder()
    {
        return $this->getDbConnection()->getSchema()->getCommandBuilder();
    }

    /**
     * Checks whether this AR has the named attribute
     * @param string $name attribute name
     * @return boolean whether this AR has the named attribute (table column).
     */
    public function hasAttribute($name)
    {
        return isset($this->getMetaData()->columns[$name]);
    }

    /**
     * Returns the named attribute value.
     * If this is a new record and the attribute is not set before,
     * the default column value will be returned.
     * If this record is the result of a query and the attribute is not loaded,
     * null will be returned.
     * You may also use $this->AttributeName to obtain the attribute value.
     * @param string $name the attribute name
     * @return mixed the attribute value. Null if the attribute is not set or does not exist.
     * @see hasAttribute
     */
    public function getAttribute($name)
    {
        if(property_exists($this,$name))
            return $this->$name;
        elseif(isset($this->_attributes[$name]))
            return $this->_attributes[$name];
    }

    /**
     * Sets the named attribute value.
     * You may also use $this->AttributeName to set the attribute value.
     * @param string $name the attribute name
     * @param mixed $value the attribute value.
     * @return boolean whether the attribute exists and the assignment is conducted successfully
     * @see hasAttribute
     */
    public function setAttribute($name,$value)
    {
        if(property_exists($this,$name))
            $this->$name=$value;
        elseif(isset($this->getMetaData()->columns[$name]))
            $this->_attributes[$name]=$value;
        else
            return false;
        return true;
    }

    /**
     * Do not call this method. This method is used internally by {@link CActiveFinder} to populate
     * related objects. This method adds a related object to this record.
     * @param string $name attribute name
     * @param mixed $record the related record
     * @param mixed $index the index value in the related object collection.
     * If true, it means using zero-based integer index.
     * If false, it means a HAS_ONE or BELONGS_TO object and no index is needed.
     */
    public function addRelatedRecord($name,$record,$index)
    {
        if($index!==false)
        {
            if(!isset($this->_related[$name]))
                $this->_related[$name]=array();
            if($record instanceof CActiveRecord)
            {
                if($index===true)
                    $this->_related[$name][]=$record;
                else
                    $this->_related[$name][$index]=$record;
            }
        }
        elseif(!isset($this->_related[$name]))
            $this->_related[$name]=$record;
    }

    /**
     * Returns all column attribute values.
     * Note, related objects are not returned.
     * @param mixed $names names of attributes whose value needs to be returned.
     * If this is true (default), then all attribute values will be returned, including
     * those that are not loaded from DB (null will be returned for those attributes).
     * If this is null, all attributes except those that are not loaded from DB will be returned.
     * @return array attribute values indexed by attribute names.
     */
    public function getAttributes($names=true)
    {
        $attributes=$this->_attributes;
        foreach($this->getMetaData()->columns as $name=>$column)
        {
            if(property_exists($this,$name))
                $attributes[$name]=$this->$name;
            elseif($names===true && !isset($attributes[$name]))
                $attributes[$name]=null;
        }
        if(is_array($names))
        {
            $attrs=array();
            foreach($names as $name)
            {
                if(property_exists($this,$name))
                    $attrs[$name]=$this->$name;
                else
                    $attrs[$name]=isset($attributes[$name])?$attributes[$name]:null;
            }
            return $attrs;
        }
        else
            return $attributes;
    }

    /**
     * Saves the current record.
     *
     * The record is inserted as a row into the database table if its {@link isNewRecord}
     * property is true (usually the case when the record is created using the 'new'
     * operator). Otherwise, it will be used to update the corresponding row in the table
     * (usually the case if the record is obtained using one of those 'find' methods.)
     *
     * Validation will be performed before saving the record. If the validation fails,
     * the record will not be saved. You can call {@link getErrors()} to retrieve the
     * validation errors.
     *
     * If the record is saved via insertion, its {@link isNewRecord} property will be
     * set false, and its {@link scenario} property will be set to be 'update'.
     * And if its primary key is auto-incremental and is not set before insertion,
     * the primary key will be populated with the automatically generated key value.
     *
     * @param boolean $runValidation whether to perform validation before saving the record.
     * If the validation fails, the record will not be saved to database.
     * @param array $attributes list of attributes that need to be saved. Defaults to null,
     * meaning all attributes that are loaded from DB will be saved.
     * @return boolean whether the saving succeeds
     */
    public function save($runValidation=true,$attributes=null)
    {
        if(!$runValidation || $this->validate($attributes))
            return $this->getIsNewRecord() ? $this->insert($attributes) : $this->update($attributes);
        else
            return false;
    }

    /**
     * Returns if the current record is new.
     * @return boolean whether the record is new and should be inserted when calling {@link save}.
     * This property is automatically set in constructor and {@link populateRecord}.
     * Defaults to false, but it will be set to true if the instance is created using
     * the new operator.
     */
    public function getIsNewRecord()
    {
        return $this->_new;
    }

    /**
     * Sets if the record is new.
     * @param boolean $value whether the record is new and should be inserted when calling {@link save}.
     * @see getIsNewRecord
     */
    public function setIsNewRecord($value)
    {
        $this->_new=$value;
    }

    /**
     * This event is raised before the record is saved.
     * By setting {@link CModelEvent::isValid} to be false, the normal {@link save()} process will be stopped.
     * @param CModelEvent $event the event parameter
     */
    public function onBeforeSave($event)
    {
        $this->raiseEvent('onBeforeSave',$event);
    }

    /**
     * This event is raised after the record is saved.
     * @param CEvent $event the event parameter
     */
    public function onAfterSave($event)
    {
        $this->raiseEvent('onAfterSave',$event);
    }

    /**
     * This event is raised before the record is deleted.
     * By setting {@link CModelEvent::isValid} to be false, the normal {@link delete()} process will be stopped.
     * @param CModelEvent $event the event parameter
     */
    public function onBeforeDelete($event)
    {
        $this->raiseEvent('onBeforeDelete',$event);
    }

    /**
     * This event is raised after the record is deleted.
     * @param CEvent $event the event parameter
     */
    public function onAfterDelete($event)
    {
        $this->raiseEvent('onAfterDelete',$event);
    }

    /**
     * This event is raised before an AR finder performs a find call.
     * This can be either a call to CActiveRecords find methods or a find call
     * when model is loaded in relational context via lazy or eager loading.
     * If you want to access or modify the query criteria used for the
     * find call, you can use {@link getDbCriteria()} to customize it based on your needs.
     * When modifying criteria in beforeFind you have to make sure you are using the right
     * table alias which is different on normal find and relational call.
     * You can use {@link getTableAlias()} to get the alias used for the upcoming find call.
     * Please note that modification of criteria is fully supported as of version 1.1.13.
     * Earlier versions had some problems with relational context and applying changes correctly.
     * @param CModelEvent $event the event parameter
     * @see beforeFind
     */
    public function onBeforeFind($event)
    {
        $this->raiseEvent('onBeforeFind',$event);
    }

    /**
     * This event is raised after the record is instantiated by a find method.
     * @param CEvent $event the event parameter
     */
    public function onAfterFind($event)
    {
        $this->raiseEvent('onAfterFind',$event);
    }

    /**
     * This method is invoked before saving a record (after validation, if any).
     * The default implementation raises the {@link onBeforeSave} event.
     * You may override this method to do any preparation work for record saving.
     * Use {@link isNewRecord} to determine whether the saving is
     * for inserting or updating record.
     * Make sure you call the parent implementation so that the event is raised properly.
     * @return boolean whether the saving should be executed. Defaults to true.
     */
    protected function beforeSave()
    {
        if($this->hasEventHandler('onBeforeSave'))
        {
            $event=new CModelEvent($this);
            $this->onBeforeSave($event);
            return $event->isValid;
        }
        else
            return true;
    }

    /**
     * This method is invoked after saving a record successfully.
     * The default implementation raises the {@link onAfterSave} event.
     * You may override this method to do postprocessing after record saving.
     * Make sure you call the parent implementation so that the event is raised properly.
     */
    protected function afterSave()
    {
        if($this->hasEventHandler('onAfterSave'))
            $this->onAfterSave(new CEvent($this));
    }

    /**
     * This method is invoked before deleting a record.
     * The default implementation raises the {@link onBeforeDelete} event.
     * You may override this method to do any preparation work for record deletion.
     * Make sure you call the parent implementation so that the event is raised properly.
     * @return boolean whether the record should be deleted. Defaults to true.
     */
    protected function beforeDelete()
    {
        if($this->hasEventHandler('onBeforeDelete'))
        {
            $event=new CModelEvent($this);
            $this->onBeforeDelete($event);
            return $event->isValid;
        }
        else
            return true;
    }

    /**
     * This method is invoked after deleting a record.
     * The default implementation raises the {@link onAfterDelete} event.
     * You may override this method to do postprocessing after the record is deleted.
     * Make sure you call the parent implementation so that the event is raised properly.
     */
    protected function afterDelete()
    {
        if($this->hasEventHandler('onAfterDelete'))
            $this->onAfterDelete(new CEvent($this));
    }

    /**
     * This method is invoked before an AR finder executes a find call.
     * The find calls include {@link find}, {@link findAll}, {@link findByPk},
     * {@link findAllByPk}, {@link findByAttributes}, {@link findAllByAttributes},
     * {@link findBySql} and {@link findAllBySql}.
     * The default implementation raises the {@link onBeforeFind} event.
     * If you override this method, make sure you call the parent implementation
     * so that the event is raised properly.
     * For details on modifying query criteria see {@link onBeforeFind} event.
     */
    protected function beforeFind()
    {
        if($this->hasEventHandler('onBeforeFind'))
        {
            $event=new CModelEvent($this);
            $this->onBeforeFind($event);
        }
    }

    /**
     * This method is invoked after each record is instantiated by a find method.
     * The default implementation raises the {@link onAfterFind} event.
     * You may override this method to do postprocessing after each newly found record is instantiated.
     * Make sure you call the parent implementation so that the event is raised properly.
     */
    protected function afterFind()
    {
        if($this->hasEventHandler('onAfterFind'))
            $this->onAfterFind(new CEvent($this));
    }

    /**
     * Calls {@link beforeFind}.
     * This method is internally used.
     */
    public function beforeFindInternal()
    {
        $this->beforeFind();
    }

    /**
     * Calls {@link afterFind}.
     * This method is internally used.
     */
    public function afterFindInternal()
    {
        $this->afterFind();
    }

    /**
     * Inserts a row into the table based on this active record attributes.
     * If the table's primary key is auto-incremental and is null before insertion,
     * it will be populated with the actual value after insertion.
     * Note, validation is not performed in this method. You may call {@link validate} to perform the validation.
     * After the record is inserted to DB successfully, its {@link isNewRecord} property will be set false,
     * and its {@link scenario} property will be set to be 'update'.
     * @param array $attributes list of attributes that need to be saved. Defaults to null,
     * meaning all attributes that are loaded from DB will be saved.
     * @return boolean whether the attributes are valid and the record is inserted successfully.
     * @throws CDbException if the record is not new
     */
    public function insert($attributes=null)
    {
        if(!$this->getIsNewRecord())
            throw new CDbException(Yii::t('yii','The active record cannot be inserted to database because it is not new.'));
        if($this->beforeSave())
        {
            Yii::trace(get_class($this).'.insert()','system.db.ar.CActiveRecord');
            $builder=$this->getCommandBuilder();
            $table=$this->getMetaData()->tableSchema;
            $command=$builder->createInsertCommand($table,$this->getAttributes($attributes));
            if($command->execute())
            {
                $primaryKey=$table->primaryKey;
                if($table->sequenceName!==null)
                {
                    if(is_string($primaryKey) && $this->$primaryKey===null)
                        $this->$primaryKey=$builder->getLastInsertID($table);
                    elseif(is_array($primaryKey))
                    {
                        foreach($primaryKey as $pk)
                        {
                            if($this->$pk===null)
                            {
                                $this->$pk=$builder->getLastInsertID($table);
                                break;
                            }
                        }
                    }
                }
                $this->_pk=$this->getPrimaryKey();
                $this->afterSave();
                $this->setIsNewRecord(false);
                $this->setScenario('update');
                return true;
            }
        }
        return false;
    }

    /**
     * Updates the row represented by this active record.
     * All loaded attributes will be saved to the database.
     * Note, validation is not performed in this method. You may call {@link validate} to perform the validation.
     * @param array $attributes list of attributes that need to be saved. Defaults to null,
     * meaning all attributes that are loaded from DB will be saved.
     * @return boolean whether the update is successful
     * @throws CDbException if the record is new
     */
    public function update($attributes=null)
    {
        if($this->getIsNewRecord())
            throw new CDbException(Yii::t('yii','The active record cannot be updated because it is new.'));
        if($this->beforeSave())
        {
            Yii::trace(get_class($this).'.update()','system.db.ar.CActiveRecord');
            if($this->_pk===null)
                $this->_pk=$this->getPrimaryKey();
            $this->updateByPk($this->getOldPrimaryKey(),$this->getAttributes($attributes));
            $this->_pk=$this->getPrimaryKey();
            $this->afterSave();
            return true;
        }
        else
            return false;
    }

    /**
     * Saves a selected list of attributes.
     * Unlike {@link save}, this method only saves the specified attributes
     * of an existing row dataset and does NOT call either {@link beforeSave} or {@link afterSave}.
     * Also note that this method does neither attribute filtering nor validation.
     * So do not use this method with untrusted data (such as user posted data).
     * You may consider the following alternative if you want to do so:
     * <pre>
     * $postRecord=Post::model()->findByPk($postID);
     * $postRecord->attributes=$_POST['post'];
     * $postRecord->save();
     * </pre>
     * @param array $attributes attributes to be updated. Each element represents an attribute name
     * or an attribute value indexed by its name. If the latter, the record's
     * attribute will be changed accordingly before saving.
     * @return boolean whether the update is successful
     * @throws CException if the record is new or any database error
     */
    public function saveAttributes($attributes)
    {
        if(!$this->getIsNewRecord())
        {
            Yii::trace(get_class($this).'.saveAttributes()','system.db.ar.CActiveRecord');
            $values=array();
            foreach($attributes as $name=>$value)
            {
                if(is_integer($name))
                    $values[$value]=$this->$value;
                else
                    $values[$name]=$this->$name=$value;
            }
            if($this->_pk===null)
                $this->_pk=$this->getPrimaryKey();
            if($this->updateByPk($this->getOldPrimaryKey(),$values)>0)
            {
                $this->_pk=$this->getPrimaryKey();
                return true;
            }
            else
                return false;
        }
        else
            throw new CDbException(Yii::t('yii','The active record cannot be updated because it is new.'));
    }

    /**
     * Saves one or several counter columns for the current AR object.
     * Note that this method differs from {@link updateCounters} in that it only
     * saves the current AR object.
     * An example usage is as follows:
     * <pre>
     * $postRecord=Post::model()->findByPk($postID);
     * $postRecord->saveCounters(array('view_count'=>1));
     * </pre>
     * Use negative values if you want to decrease the counters.
     * @param array $counters the counters to be updated (column name=>increment value)
     * @return boolean whether the saving is successful
     * @see updateCounters
     * @since 1.1.8
     */
    public function saveCounters($counters)
    {
        Yii::trace(get_class($this).'.saveCounters()','system.db.ar.CActiveRecord');
        $builder=$this->getCommandBuilder();
        $table=$this->getTableSchema();
        $criteria=$builder->createPkCriteria($table,$this->getOldPrimaryKey());
        $command=$builder->createUpdateCounterCommand($this->getTableSchema(),$counters,$criteria);
        if($command->execute())
        {
            foreach($counters as $name=>$value)
                $this->$name=$this->$name+$value;
            return true;
        }
        else
            return false;
    }

    /**
     * Deletes the row corresponding to this active record.
     * @return boolean whether the deletion is successful.
     * @throws CException if the record is new
     */
    public function delete()
    {
        if(!$this->getIsNewRecord())
        {
            Yii::trace(get_class($this).'.delete()','system.db.ar.CActiveRecord');
            if($this->beforeDelete())
            {
                $result=$this->deleteByPk($this->getPrimaryKey())>0;
                $this->afterDelete();
                return $result;
            }
            else
                return false;
        }
        else
            throw new CDbException(Yii::t('yii','The active record cannot be deleted because it is new.'));
    }

    /**
     * Repopulates this active record with the latest data.
     * @return boolean whether the row still exists in the database. If true, the latest data will be populated to this active record.
     */
    public function refresh()
    {
        Yii::trace(get_class($this).'.refresh()','system.db.ar.CActiveRecord');
        if(($record=$this->findByPk($this->getPrimaryKey()))!==null)
        {
            $this->_attributes=array();
            $this->_related=array();
            foreach($this->getMetaData()->columns as $name=>$column)
            {
                if(property_exists($this,$name))
                    $this->$name=$record->$name;
                else
                    $this->_attributes[$name]=$record->$name;
            }
            return true;
        }
        else
            return false;
    }

    /**
     * Compares current active record with another one.
     * The comparison is made by comparing table name and the primary key values of the two active records.
     * @param CActiveRecord $record record to compare to
     * @return boolean whether the two active records refer to the same row in the database table.
     */
    public function equals($record)
    {
        return $this->tableName()===$record->tableName() && $this->getPrimaryKey()===$record->getPrimaryKey();
    }

    /**
     * Returns the primary key value.
     * @return mixed the primary key value. An array (column name=>column value) is returned if the primary key is composite.
     * If primary key is not defined, null will be returned.
     */
    public function getPrimaryKey()
    {
        $table=$this->getMetaData()->tableSchema;
        if(is_string($table->primaryKey))
            return $this->{$table->primaryKey};
        elseif(is_array($table->primaryKey))
        {
            $values=array();
            foreach($table->primaryKey as $name)
                $values[$name]=$this->$name;
            return $values;
        }
        else
            return null;
    }

    /**
     * Sets the primary key value.
     * After calling this method, the old primary key value can be obtained from {@link oldPrimaryKey}.
     * @param mixed $value the new primary key value. If the primary key is composite, the new value
     * should be provided as an array (column name=>column value).
     * @since 1.1.0
     */
    public function setPrimaryKey($value)
    {
        $this->_pk=$this->getPrimaryKey();
        $table=$this->getMetaData()->tableSchema;
        if(is_string($table->primaryKey))
            $this->{$table->primaryKey}=$value;
        elseif(is_array($table->primaryKey))
        {
            foreach($table->primaryKey as $name)
                $this->$name=$value[$name];
        }
    }

    /**
     * Returns the old primary key value.
     * This refers to the primary key value that is populated into the record
     * after executing a find method (e.g. find(), findAll()).
     * The value remains unchanged even if the primary key attribute is manually assigned with a different value.
     * @return mixed the old primary key value. An array (column name=>column value) is returned if the primary key is composite.
     * If primary key is not defined, null will be returned.
     * @since 1.1.0
     */
    public function getOldPrimaryKey()
    {
        return $this->_pk;
    }

    /**
     * Sets the old primary key value.
     * @param mixed $value the old primary key value.
     * @since 1.1.3
     */
    public function setOldPrimaryKey($value)
    {
        $this->_pk=$value;
    }

    /**
     * Performs the actual DB query and populates the AR objects with the query result.
     * This method is mainly internally used by other AR query methods.
     * @param CDbCriteria $criteria the query criteria
     * @param boolean $all whether to return all data
     * @return mixed the AR objects populated with the query result
     * @since 1.1.7
     */
    protected function query($criteria,$all=false)
    {
        $this->beforeFind();
        $this->applyScopes($criteria);

        if(empty($criteria->with))
        {
            if(!$all)
                $criteria->limit=1;
            $command=$this->getCommandBuilder()->createFindCommand($this->getTableSchema(),$criteria);
            return $all ? $this->populateRecords($command->queryAll(), true, $criteria->index) : $this->populateRecord($command->queryRow());
        }
        else
        {
            $finder=new CActiveFinder($this,$criteria->with);
            return $finder->query($criteria,$all);
        }
    }

    /**
     * Applies the query scopes to the given criteria.
     * This method merges {@link dbCriteria} with the given criteria parameter.
     * It then resets {@link dbCriteria} to be null.
     * @param CDbCriteria $criteria the query criteria. This parameter may be modified by merging {@link dbCriteria}.
     */
    public function applyScopes(&$criteria)
    {
        if(!empty($criteria->scopes))
        {
            $scs=$this->scopes();
            $c=$this->getDbCriteria();
            foreach((array)$criteria->scopes as $k=>$v)
            {
                if(is_integer($k))
                {
                    if(is_string($v))
                    {
                        if(isset($scs[$v]))
                        {
                            $c->mergeWith($scs[$v],true);
                            continue;
                        }
                        $scope=$v;
                        $params=array();
                    }
                    elseif(is_array($v))
                    {
                        $scope=key($v);
                        $params=current($v);
                    }
                }
                elseif(is_string($k))
                {
                    $scope=$k;
                    $params=$v;
                }

                call_user_func_array(array($this,$scope),(array)$params);
            }
        }

        if(isset($c) || ($c=$this->getDbCriteria(false))!==null)
        {
            $c->mergeWith($criteria);
            $criteria=$c;
            $this->resetScope(false);
        }
    }

    /**
     * Returns the table alias to be used by the find methods.
     * In relational queries, the returned table alias may vary according to
     * the corresponding relation declaration. Also, the default table alias
     * set by {@link setTableAlias} may be overridden by the applied scopes.
     * @param boolean $quote whether to quote the alias name
     * @param boolean $checkScopes whether to check if a table alias is defined in the applied scopes so far.
     * This parameter must be set false when calling this method in {@link defaultScope}.
     * An infinite loop would be formed otherwise.
     * @return string the default table alias
     * @since 1.1.1
     */
    public function getTableAlias($quote=false, $checkScopes=true)
    {
        if($checkScopes && ($criteria=$this->getDbCriteria(false))!==null && $criteria->alias!='')
            $alias=$criteria->alias;
        else
            $alias=$this->_alias;
        return $quote ? $this->getDbConnection()->getSchema()->quoteTableName($alias) : $alias;
    }

    /**
     * Sets the table alias to be used in queries.
     * @param string $alias the table alias to be used in queries. The alias should NOT be quoted.
     * @since 1.1.3
     */
    public function setTableAlias($alias)
    {
        $this->_alias=$alias;
    }

    /**
     * Finds a single active record with the specified condition.
     * @param mixed $condition query condition or criteria.
     * If a string, it is treated as query condition (the WHERE clause);
     * If an array, it is treated as the initial values for constructing a {@link CDbCriteria} object;
     * Otherwise, it should be an instance of {@link CDbCriteria}.
     * @param array $params parameters to be bound to an SQL statement.
     * This is only used when the first parameter is a string (query condition).
     * In other cases, please use {@link CDbCriteria::params} to set parameters.
     * @return CActiveRecord the record found. Null if no record is found.
     */
    public function find($condition='',$params=array())
    {
        Yii::trace(get_class($this).'.find()','system.db.ar.CActiveRecord');
        $criteria=$this->getCommandBuilder()->createCriteria($condition,$params);
        return $this->query($criteria);
    }

    /**
     * Finds all active records satisfying the specified condition.
     * See {@link find()} for detailed explanation about $condition and $params.
     * @param mixed $condition query condition or criteria.
     * @param array $params parameters to be bound to an SQL statement.
     * @return array list of active records satisfying the specified condition. An empty array is returned if none is found.
     */
    public function findAll($condition='',$params=array())
    {
        Yii::trace(get_class($this).'.findAll()','system.db.ar.CActiveRecord');
        $criteria=$this->getCommandBuilder()->createCriteria($condition,$params);
        return $this->query($criteria,true);
    }

    /**
     * Finds a single active record with the specified primary key.
     * See {@link find()} for detailed explanation about $condition and $params.
     * @param mixed $pk primary key value(s). Use array for multiple primary keys. For composite key, each key value must be an array (column name=>column value).
     * @param mixed $condition query condition or criteria.
     * @param array $params parameters to be bound to an SQL statement.
     * @return CActiveRecord the record found. Null if none is found.
     */
    public function findByPk($pk,$condition='',$params=array())
    {
        Yii::trace(get_class($this).'.findByPk()','system.db.ar.CActiveRecord');
        $prefix=$this->getTableAlias(true).'.';
        $criteria=$this->getCommandBuilder()->createPkCriteria($this->getTableSchema(),$pk,$condition,$params,$prefix);
        return $this->query($criteria);
    }

    /**
     * Finds all active records with the specified primary keys.
     * See {@link find()} for detailed explanation about $condition and $params.
     * @param mixed $pk primary key value(s). Use array for multiple primary keys. For composite key, each key value must be an array (column name=>column value).
     * @param mixed $condition query condition or criteria.
     * @param array $params parameters to be bound to an SQL statement.
     * @return array the records found. An empty array is returned if none is found.
     */
    public function findAllByPk($pk,$condition='',$params=array())
    {
        Yii::trace(get_class($this).'.findAllByPk()','system.db.ar.CActiveRecord');
        $prefix=$this->getTableAlias(true).'.';
        $criteria=$this->getCommandBuilder()->createPkCriteria($this->getTableSchema(),$pk,$condition,$params,$prefix);
        return $this->query($criteria,true);
    }

    /**
     * Finds a single active record that has the specified attribute values.
     * See {@link find()} for detailed explanation about $condition and $params.
     * @param array $attributes list of attribute values (indexed by attribute names) that the active records should match.
     * An attribute value can be an array which will be used to generate an IN condition.
     * @param mixed $condition query condition or criteria.
     * @param array $params parameters to be bound to an SQL statement.
     * @return CActiveRecord the record found. Null if none is found.
     */
    public function findByAttributes($attributes,$condition='',$params=array())
    {
        Yii::trace(get_class($this).'.findByAttributes()','system.db.ar.CActiveRecord');
        $prefix=$this->getTableAlias(true).'.';
        $criteria=$this->getCommandBuilder()->createColumnCriteria($this->getTableSchema(),$attributes,$condition,$params,$prefix);
        return $this->query($criteria);
    }

    /**
     * Finds all active records that have the specified attribute values.
     * See {@link find()} for detailed explanation about $condition and $params.
     * @param array $attributes list of attribute values (indexed by attribute names) that the active records should match.
     * An attribute value can be an array which will be used to generate an IN condition.
     * @param mixed $condition query condition or criteria.
     * @param array $params parameters to be bound to an SQL statement.
     * @return array the records found. An empty array is returned if none is found.
     */
    public function findAllByAttributes($attributes,$condition='',$params=array())
    {
        Yii::trace(get_class($this).'.findAllByAttributes()','system.db.ar.CActiveRecord');
        $prefix=$this->getTableAlias(true).'.';
        $criteria=$this->getCommandBuilder()->createColumnCriteria($this->getTableSchema(),$attributes,$condition,$params,$prefix);
        return $this->query($criteria,true);
    }

    /**
     * Finds a single active record with the specified SQL statement.
     * @param string $sql the SQL statement
     * @param array $params parameters to be bound to the SQL statement
     * @return CActiveRecord the record found. Null if none is found.
     */
    public function findBySql($sql,$params=array())
    {
        Yii::trace(get_class($this).'.findBySql()','system.db.ar.CActiveRecord');
        $this->beforeFind();
        if(($criteria=$this->getDbCriteria(false))!==null && !empty($criteria->with))
        {
            $this->resetScope(false);
            $finder=new CActiveFinder($this,$criteria->with);
            return $finder->findBySql($sql,$params);
        }
        else
        {
            $command=$this->getCommandBuilder()->createSqlCommand($sql,$params);
            return $this->populateRecord($command->queryRow());
        }
    }

    /**
     * Finds all active records using the specified SQL statement.
     * @param string $sql the SQL statement
     * @param array $params parameters to be bound to the SQL statement
     * @return array the records found. An empty array is returned if none is found.
     */
    public function findAllBySql($sql,$params=array())
    {
        Yii::trace(get_class($this).'.findAllBySql()','system.db.ar.CActiveRecord');
        $this->beforeFind();
        if(($criteria=$this->getDbCriteria(false))!==null && !empty($criteria->with))
        {
            $this->resetScope(false);
            $finder=new CActiveFinder($this,$criteria->with);
            return $finder->findAllBySql($sql,$params);
        }
        else
        {
            $command=$this->getCommandBuilder()->createSqlCommand($sql,$params);
            return $this->populateRecords($command->queryAll());
        }
    }

    /**
     * Finds the number of rows satisfying the specified query condition.
     * See {@link find()} for detailed explanation about $condition and $params.
     * @param mixed $condition query condition or criteria.
     * @param array $params parameters to be bound to an SQL statement.
     * @return string the number of rows satisfying the specified query condition. Note: type is string to keep max. precision.
     */
    public function count($condition='',$params=array())
    {
        Yii::trace(get_class($this).'.count()','system.db.ar.CActiveRecord');
        $builder=$this->getCommandBuilder();
        $criteria=$builder->createCriteria($condition,$params);
        $this->applyScopes($criteria);

        if(empty($criteria->with))
            return $builder->createCountCommand($this->getTableSchema(),$criteria)->queryScalar();
        else
        {
            $finder=new CActiveFinder($this,$criteria->with);
            return $finder->count($criteria);
        }
    }

    /**
     * Finds the number of rows that have the specified attribute values.
     * See {@link find()} for detailed explanation about $condition and $params.
     * @param array $attributes list of attribute values (indexed by attribute names) that the active records should match.
     * An attribute value can be an array which will be used to generate an IN condition.
     * @param mixed $condition query condition or criteria.
     * @param array $params parameters to be bound to an SQL statement.
     * @return string the number of rows satisfying the specified query condition. Note: type is string to keep max. precision.
     * @since 1.1.4
     */
    public function countByAttributes($attributes,$condition='',$params=array())
    {
        Yii::trace(get_class($this).'.countByAttributes()','system.db.ar.CActiveRecord');
        $prefix=$this->getTableAlias(true).'.';
        $builder=$this->getCommandBuilder();
        $criteria=$builder->createColumnCriteria($this->getTableSchema(),$attributes,$condition,$params,$prefix);
        $this->applyScopes($criteria);

        if(empty($criteria->with))
            return $builder->createCountCommand($this->getTableSchema(),$criteria)->queryScalar();
        else
        {
            $finder=new CActiveFinder($this,$criteria->with);
            return $finder->count($criteria);
        }
    }

    /**
     * Finds the number of rows using the given SQL statement.
     * This is equivalent to calling {@link CDbCommand::queryScalar} with the specified
     * SQL statement and the parameters.
     * @param string $sql the SQL statement
     * @param array $params parameters to be bound to the SQL statement
     * @return string the number of rows using the given SQL statement. Note: type is string to keep max. precision.
     */
    public function countBySql($sql,$params=array())
    {
        Yii::trace(get_class($this).'.countBySql()','system.db.ar.CActiveRecord');
        return $this->getCommandBuilder()->createSqlCommand($sql,$params)->queryScalar();
    }

    /**
     * Checks whether there is row satisfying the specified condition.
     * See {@link find()} for detailed explanation about $condition and $params.
     * @param mixed $condition query condition or criteria.
     * @param array $params parameters to be bound to an SQL statement.
     * @return boolean whether there is row satisfying the specified condition.
     */
    public function exists($condition='',$params=array())
    {
        Yii::trace(get_class($this).'.exists()','system.db.ar.CActiveRecord');
        $builder=$this->getCommandBuilder();
        $criteria=$builder->createCriteria($condition,$params);
        $table=$this->getTableSchema();
        $criteria->select='1';
        $criteria->limit=1;
        $this->applyScopes($criteria);

        if(empty($criteria->with))
            return $builder->createFindCommand($table,$criteria)->queryRow()!==false;
        else
        {
            $criteria->select='*';
            $finder=new CActiveFinder($this,$criteria->with);
            return $finder->count($criteria)>0;
        }
    }

    /**
     * Specifies which related objects should be eagerly loaded.
     * This method takes variable number of parameters. Each parameter specifies
     * the name of a relation or child-relation. For example,
     * <pre>
     * // find all posts together with their author and comments
     * Post::model()->with('author','comments')->findAll();
     * // find all posts together with their author and the author's profile
     * Post::model()->with('author','author.profile')->findAll();
     * </pre>
     * The relations should be declared in {@link relations()}.
     *
     * By default, the options specified in {@link relations()} will be used
     * to do relational query. In order to customize the options on the fly,
     * we should pass an array parameter to the with() method. The array keys
     * are relation names, and the array values are the corresponding query options.
     * For example,
     * <pre>
     * Post::model()->with(array(
     *     'author'=>array('select'=>'id, name'),
     *     'comments'=>array('condition'=>'approved=1', 'order'=>'create_time'),
     * ))->findAll();
     * </pre>
     *
     * @return CActiveRecord the AR object itself.
     */
    public function with()
    {
        if(func_num_args()>0)
        {
            $with=func_get_args();
            if(is_array($with[0]))  // the parameter is given as an array
                $with=$with[0];
            if(!empty($with))
                $this->getDbCriteria()->mergeWith(array('with'=>$with));
        }
        return $this;
    }

    /**
     * Sets {@link CDbCriteria::together} property to be true.
     * This is only used in relational AR query. Please refer to {@link CDbCriteria::together}
     * for more details.
     * @return CActiveRecord the AR object itself
     * @since 1.1.4
     */
    public function together()
    {
        $this->getDbCriteria()->together=true;
        return $this;
    }

    /**
     * Updates records with the specified primary key(s).
     * See {@link find()} for detailed explanation about $condition and $params.
     * Note, the attributes are not checked for safety and validation is NOT performed.
     * @param mixed $pk primary key value(s). Use array for multiple primary keys. For composite key, each key value must be an array (column name=>column value).
     * @param array $attributes list of attributes (name=>$value) to be updated
     * @param mixed $condition query condition or criteria.
     * @param array $params parameters to be bound to an SQL statement.
     * @return integer the number of rows being updated
     */
    public function updateByPk($pk,$attributes,$condition='',$params=array())
    {
        Yii::trace(get_class($this).'.updateByPk()','system.db.ar.CActiveRecord');
        $builder=$this->getCommandBuilder();
        $table=$this->getTableSchema();
        $criteria=$builder->createPkCriteria($table,$pk,$condition,$params);
        $command=$builder->createUpdateCommand($table,$attributes,$criteria);
        return $command->execute();
    }

    /**
     * Updates records with the specified condition.
     * See {@link find()} for detailed explanation about $condition and $params.
     * Note, the attributes are not checked for safety and no validation is done.
     * @param array $attributes list of attributes (name=>$value) to be updated
     * @param mixed $condition query condition or criteria.
     * @param array $params parameters to be bound to an SQL statement.
     * @return integer the number of rows being updated
     */
    public function updateAll($attributes,$condition='',$params=array())
    {
        Yii::trace(get_class($this).'.updateAll()','system.db.ar.CActiveRecord');
        $builder=$this->getCommandBuilder();
        $criteria=$builder->createCriteria($condition,$params);
        $command=$builder->createUpdateCommand($this->getTableSchema(),$attributes,$criteria);
        return $command->execute();
    }

    /**
     * Updates one or several counter columns.
     * Note, this updates all rows of data unless a condition or criteria is specified.
     * See {@link find()} for detailed explanation about $condition and $params.
     * @param array $counters the counters to be updated (column name=>increment value)
     * @param mixed $condition query condition or criteria.
     * @param array $params parameters to be bound to an SQL statement.
     * @return integer the number of rows being updated
     * @see saveCounters
     */
    public function updateCounters($counters,$condition='',$params=array())
    {
        Yii::trace(get_class($this).'.updateCounters()','system.db.ar.CActiveRecord');
        $builder=$this->getCommandBuilder();
        $criteria=$builder->createCriteria($condition,$params);
        $command=$builder->createUpdateCounterCommand($this->getTableSchema(),$counters,$criteria);
        return $command->execute();
    }

    /**
     * Deletes rows with the specified primary key.
     * See {@link find()} for detailed explanation about $condition and $params.
     * @param mixed $pk primary key value(s). Use array for multiple primary keys. For composite key, each key value must be an array (column name=>column value).
     * @param mixed $condition query condition or criteria.
     * @param array $params parameters to be bound to an SQL statement.
     * @return integer the number of rows deleted
     */
    public function deleteByPk($pk,$condition='',$params=array())
    {
        Yii::trace(get_class($this).'.deleteByPk()','system.db.ar.CActiveRecord');
        $builder=$this->getCommandBuilder();
        $criteria=$builder->createPkCriteria($this->getTableSchema(),$pk,$condition,$params);
        $command=$builder->createDeleteCommand($this->getTableSchema(),$criteria);
        return $command->execute();
    }

    /**
     * Deletes rows with the specified condition.
     * See {@link find()} for detailed explanation about $condition and $params.
     * @param mixed $condition query condition or criteria.
     * @param array $params parameters to be bound to an SQL statement.
     * @return integer the number of rows deleted
     */
    public function deleteAll($condition='',$params=array())
    {
        Yii::trace(get_class($this).'.deleteAll()','system.db.ar.CActiveRecord');
        $builder=$this->getCommandBuilder();
        $criteria=$builder->createCriteria($condition,$params);
        $command=$builder->createDeleteCommand($this->getTableSchema(),$criteria);
        return $command->execute();
    }

    /**
     * Deletes rows which match the specified attribute values.
     * See {@link find()} for detailed explanation about $condition and $params.
     * @param array $attributes list of attribute values (indexed by attribute names) that the active records should match.
     * An attribute value can be an array which will be used to generate an IN condition.
     * @param mixed $condition query condition or criteria.
     * @param array $params parameters to be bound to an SQL statement.
     * @return integer number of rows affected by the execution.
     */
    public function deleteAllByAttributes($attributes,$condition='',$params=array())
    {
        Yii::trace(get_class($this).'.deleteAllByAttributes()','system.db.ar.CActiveRecord');
        $builder=$this->getCommandBuilder();
        $table=$this->getTableSchema();
        $criteria=$builder->createColumnCriteria($table,$attributes,$condition,$params);
        $command=$builder->createDeleteCommand($table,$criteria);
        return $command->execute();
    }

    /**
     * Creates an active record with the given attributes.
     * This method is internally used by the find methods.
     * @param array $attributes attribute values (column name=>column value)
     * @param boolean $callAfterFind whether to call {@link afterFind} after the record is populated.
     * @return CActiveRecord the newly created active record. The class of the object is the same as the model class.
     * Null is returned if the input data is false.
     */
    public function populateRecord($attributes,$callAfterFind=true)
    {
        if($attributes!==false)
        {
            $record=$this->instantiate($attributes);
            $record->setScenario('update');
            $record->init();
            $md=$record->getMetaData();
            foreach($attributes as $name=>$value)
            {
                if(property_exists($record,$name))
                    $record->$name=$value;
                elseif(isset($md->columns[$name]))
                    $record->_attributes[$name]=$value;
            }
            $record->_pk=$record->getPrimaryKey();
            $record->attachBehaviors($record->behaviors());
            if($callAfterFind)
                $record->afterFind();
            return $record;
        }
        else
            return null;
    }

    /**
     * Creates a list of active records based on the input data.
     * This method is internally used by the find methods.
     * @param array $data list of attribute values for the active records.
     * @param boolean $callAfterFind whether to call {@link afterFind} after each record is populated.
     * @param string $index the name of the attribute whose value will be used as indexes of the query result array.
     * If null, it means the array will be indexed by zero-based integers.
     * @return array list of active records.
     */
    public function populateRecords($data,$callAfterFind=true,$index=null)
    {
        $records=array();
        foreach($data as $attributes)
        {
            if(($record=$this->populateRecord($attributes,$callAfterFind))!==null)
            {
                if($index===null)
                    $records[]=$record;
                else
                    $records[$record->$index]=$record;
            }
        }
        return $records;
    }

    /**
     * Returns whether there is an element at the specified offset.
     * This method is required by the interface ArrayAccess.
     * @param mixed $offset the offset to check on
     * @return boolean
     */
    public function offsetExists($offset)
    {
        return $this->__isset($offset);
    }
}