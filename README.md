## CDynamicRecord

CDynamicRecord is an ActiveRecord implementation for Yii that allows you access two identical database tables across multiple databases through a single model rather than creating multiple models for each database. CDynamicRecord has the same behaviors/syntax and CActiveRecord

### Why I Built This
I built this because I wanted 1 codebase for the entire project and didn't want to have multiple classes with a slightly altered CDbConnectionString in order to access them. This was easier than that. I didn't want to instantiate a new copy of the application every time I wanted to make another db copy.

--------------------------------------
#### FAIR WARNING
You need to follow all of these instructions exactly in order to get CDynamicRecord to work. The instructions _look_ very complex, but they really aren't. You can do it. =)

--------------------------------------

For example, suppose you had two databases with the same structure, db1 and db2. Each database has a table called foo which has distinct, nonrelated data, so you're structure looks something like this:

~~~
- db1
   \ - foo
- db2
   \ - foo
~~~

Since both tables are identical and only have different data, accessing them requires EITHER two distinct ActiveRecord classes, each one configured for the appropriate database. Alternatively you could use this class which would allow you to access BOTH databases independently of one another without having to create a new class Foo model for each database. That is what CDynamicRecord is for.

### Installation
* Clone CDynamicRecord.php to your Application/Protected/Components folder.
* Create a structure only copy of your database with the appropriate permissions and access. For example, I've named the strucutre only copy of my database db_base/

~~~
- db_base
- db1
   \ - foo
- db2
   \ - foo
~~~

* Create a default db configuration which has access to db_base.
* Modify CDynamicRecord::overrideDbConnection() and replace the following line:

~~~
# MODIFY YOUR CDBCONNECTIONSTRING HERE
~~~

With

~~~
Yii::app()->db->connectionString = // CONNECTION STRING INFORMATION
~~~

The variable

~~~
CDynamicModel::$dbConnectionString
~~~

Is publicly accessible inside the model, and will contain any information you want to pass into the model for connection information at call time. You _should_ use it to define how you want to connect to your database.

It is __YOUR__ responsibility to define __HOW__ you want to connect to the database.

* Create a new class Foo and have it extend CDynamicRecord, and modify model() so that it looks as follows.

~~~
    public static function model($dbConnection, $className=__CLASS__)
    {
        return parent::model($dbConnection, $className);
    }
~~~  

### Usage
After setting everything up, CDynamicRecord behaves exactly like CActiveRecord with the expection that you must specify the connection details at call time. Calls then look like this:

~~~
Foo::model($connection)->findAll();
$foo = new Foo($connection2);
$foo->relation->findAll();
~~~

__$connection__ can be whatever you want, it just needs to be defined. It could be an array containing the data, or a flat out string.

#### Relations

Relations will still work the same, but depending upon their usage you may need to change a few things.

__IF__ you plan on accessing a related model (Bar), and you have no intention on calling Bar by itself. Then Bar should extend __CActiveRecord__, and in Foo you can define normal relations. Yii magically carries over the CDbConnectionString across the instances for you.

__OTHERWISE__, if you intend to access models in the same database, but also want to retain the ability to call them by themselves, then Bar should extend CDynamicModel, and Foo should have a getter defined as follows.

~~~
// This is an example for access Bar from Foo

public function getBar()
{
    return Bar::model($this->$dbConnectionString);
}
~~~

### Examples

This is an example using a model Foo which connects to a table db*/foo

##### Database
~~~
db_base // Structure copy only
    \ - foo
db_1    // Contains data
    \ - foo
db_2    // Data of same structure of db_1/foo, but is different.
    \ - foo
~~~

##### Foo Model
~~~
<?php // Application/Protected/Models/Foo.php
class Foo extends CDynamicRecord
{
    public static function model($dbConnection, $className=__CLASS__)
    {
        return parent::model($dbConnection, $className);
    }
}
~~~

##### CDynamicRecord::overrideDbConnection()
Just update overrideDbConnection()
~~~
<?php
class CDynamicRecord extends CActiveRecord
{
    [...]
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
    [...]
}
~~~

With this configuration, db_base, db_1, and db_2 use the same credentials. The same user can access each of them as defined in my default db connection string in my __Application/Protected/Config/main.php__ file.

So now, I can do this:

~~~
$foo = Foo(1);
$foo2 = Foo(2);
$foo3 = Foo::model(1);
~~~


# CDynamicRecordSDB

CDynamicRecordSDB is the same thing as CDynamicRecord, except instead of working with identical tables across multiple databases, it works with multiple tables in the same database. The structures must be 100% identical.

Setup is identical to CDynamicRecord, the only difference is that instead of extending CDynamicRecord, you extend CDynamicRecordSDB. 

### Examples

~~~
// test.php

Yii::import('ext.CDynamicRecord.*');
class Test extends CDynamicRecordSDB
{
    public static function model($dbConnectionString = 0, $className=__CLASS__)
    {
        return parent::model($dbConnectionString, $className);
    }


    public function tableName()
    {
    return $this->dbConnectionString;
    }

     [... Do everything else after this ...]
}
~~~

And then the callback looks like:

~~~
$data = new Test('tbl_name');
$data2 = Test::model('tbl_name');
$data3 = Test::model('tbl_name2');
$data3 = new Test('tbl_name2');
~~~
