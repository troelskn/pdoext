About pdoext [![Build Status](https://secure.travis-ci.org/troelskn/pdoext.png?branch=master)](http://travis-ci.org/troelskn/pdoext)
===

**pdoext** is a database abstraction layer for php. Its main features are **zero-configuration** and an **elegant api**.

**pdoext** extends **[pdo](http://www.php.net/manual/en/class.pdo.php)** and adds missing functionality, such as **logging** and **introspection** ; convenience functionality such as **quoting of fields** and assertion of **transactions** ; as well as provides some workarounds for compatibility problems (mainly with [sqlite](http://www.sqlite.org/))

Most notably, it provides a **tablegateway**, that abstracts most of the rudimentary SQL out in an intuitive and readable interface. Scroll down for examples.

It is *not* a full-blown ORM; You still need to understand the underlying database to use it efficiently. This helps to *keep the complexity down*, making pdoext relatively simple to comprehend and extend. In particular, *pdoext doesn't manage* **object identity** or **inheritance**. Nor does it isolate your application code completely from the **relational paradigm** of databases.

Connection
===

Since pdoext extends PDO, the connection objects follows the same interface. You can look at the documentation at [pdo constructor](http://www.php.net/manual/en/function.PDO-construct.php) to see what arguments it takes. Here is a simple example of connecting to a local MySql database:

    $db = new pdoext_Connection("mysql:dbname=testdb;host=127.0.0.1", "root", "secret");

One benefit of this loose relationship between pdo and pdoext is that pdoext can be used in any place where pdo is expected.

Table Gateway
===

The table gateway gives you access to simple **CRUD operations** and to querying for rows. It returns rows in an **active record** style wrapper, that can be extended in user land code. Here's how you would typically use the tablegateway in an application:

    foreach ($db->articles->whereStatusIs('published') as $article) {
      print $article->title . " - " . $article->author()->name . "\n";
    }

No configuration and no userland code is required to use this. **pdoext** will use introspection to figure out how your tables are linked together, using [foreign key](http://en.wikipedia.org/wiki/Foreign_key) constraints.

Conditions
---

When selecting from a gateway, you can use a variety of conditions. Assuming a column *name*, the following conditions are built-in:

method                     | SQL
-------------------------- | ------------------
`whereNameIs("jim")`       | name = "jim"
`whereNameIsNot("jim")`    | name != "jim"
`whereNameLike("jim%")`    | name LIKE "jim%"
`whereNameNotLike("jim%")` | name NOT LIKE "jim%"
`whereNameGreaterThan(42)` | name > 42
`whereNameLesserThan(42)`  | name < 42
`whereNameIsNull()`        | name IS NULL
`whereNameIsNotNull()`     | name IS NOT NULL

You can add your own conditions (See under **scopes** for details).

Pagination
---

Tablegateways have built-in support for **pagination**:

    $selection = $db->users->whereNameLike('jim%')->paginate($page_number);
    echo "Viewing page " . $selection->currentPage() . " of " . $selection->totalPages() . "\n";
    foreach ($selection as $user) {
      echo "id: " . $user->id . ", name: " . $user->name . "\n";
    }

Fetch single record
---

If you expect one, and only one, record from a query, you can prepend `->one()` to the selection, to fetch the first result. It will throw an exception if there are more than one rows in the result. Eg.:

    $jim = $db->users->whereNameIs('jim')->one();

Chaining conditions
---

You can apply as many conditions as you wish to a query:

    $selection = $db->users->whereNameLike('jim%');
    $selection->whereAgeGreaterThan(27);
    foreach ($selection as $user) {
      echo "id: " . $user->id . ", name: " . $user->name . "\n";
    }

Customising
---

You can create your own tablegateway, to extend the functionality. For example:

    class ArticlesGateway extends pdoext_TableGateway {
    }

Pdoext knows to use `ArticlesGateway` by convention. If a class exists with following the pattern of *tablename*+"gateway", it will be used instead of the generic `pdoext_TableGateway`.

The most common usage is to create **scopes** (See next section) and for **validations**.

Likewise, if you create a class with the singular form of the table name, **pdoext** will use this instead of the generic `pdoext_DatabaseRecord`:

    class Article extends pdoext_DatabaseRecord {
    }

Records
===

You can create custom accessors (getters/setters) on your records. If a method named as "get"+*columnName* exists, is is called instead of updating the internal array. For example:

    class Article extends pdoext_DatabaseRecord {
      function getAge() {
        return time() - $this->createdAt;
      }
      function setTitle($title) {
        $this->_data['title'] = $title;
        $this->_data['slug'] = preg_replace('[^a-z]', '-', strtolower($title));
      }
    }

Foreign keys
---

If a table defines any foreign keys, you can access them on a record. For example:

    $article = $db->articles->whereTitleIs("Lorem Ipsum")->one();
    $author = $article->author();

It also works the other way:

    $articles = $author->articles();

Note that by default, *no attempt is done at managing identity of rows*. Each time you call these methods, a new query is executed against the database. In other words:

    $authorOne = $article->author();
    $authorTwo = $article->author();
    assert($authorOne !== $authorTwo); // yields true

In recent versions, pdoext comes with an optional object cache (See below). With the cache enabled, semantics changes, and the database is only interrogated once:

    $db->enableCache();
    $authorOne = $article->author();
    $authorTwo = $article->author();
    assert($authorOne === $authorTwo); // yields true

Apart from semantics, there are also performance implications of using an object cache/identity map and it's not clear cut which is better as it's a trade off.

Another limitation of foreign keys is that you can't assign an object directly:

    // NOTE: Won't work!
    $article->author = $db->authors->whereNameIs("Jim")->one();

Please understand that *this is by design*, as it spares us from a world of complexity related to the [object-relational impedance mismatch](http://en.wikipedia.org/wiki/Object-relational_impedance_mismatch). If you want this kind of functionality, use a full ORM, such as [Doctrine](http://www.doctrine-project.org/).

Caching
---

pdoext has an optional object cache/identity map, that caches on primary keys. The cache isn't enabled by default, since it is a tradeoff between memory usage and the number of queries against the database. However, if you have a lot of lookups on primary key, it might improve your performance to turn it on. To enable caching, call `enableCache` on the connection object. The cache lives on the table gateways, where you can also clear it, by calling `purgeCache` on the table gateway. E.g. :

    $db->enableCache(); // Enable object caching for all gateways
    $db->authors->purgeCache(); // Clear cache for the authors gateway

The cache is used when you `fetch` on primary key and whenever a record is loaded with `load`. Keep in mind that this means that records get reference semantics, rather than value semantics as is the default for pdoext.

Naming
---

It is assumed that all database column follow a convention of **lowercase_underscore**. The record will automagically convert between php-style *camelCase* and the database naming style. So you can access a column as the camelCase version from php-code. Both will work however. Ex.:

    // Recommended style
    echo $article->createdAt;
    echo $article['created_at'];

    // Will also work
    echo $article->created_at;
    echo $article['createdAt'];

In the case of accessors, you **must** write the methods in *camelCase*.

Customising Gateways
===

Scopes
---

To help keeping your code elegant and readable, you can create custom **scopes**. For example:

    class ArticlesGateway extends pdoext_TableGateway {
      function scopeWherePublished($selection) {
        $selection->where('status', 'published');
      }
    }

And now you can use the scope like this:

    foreach ($db->articles->wherePublished()->limit(10) as $article) {
      print $article->title . " - " . $article->author()->name . "\n";
    }

A scope should always begin with *where* or *with*; The convention being that *where* adds conditions and *with* joins with other tables. Here's an example joining a side table:

    class ArticlesGateway extends pdoext_TableGateway {
      function scopeWherePublished($selection) {
        $selection->where('status', 'published');
      }
      function scopeWithAuthor($selection) {
        $selection->addColumn('articles.*');
        $selection->addColumn('authors.name', 'author_name');
        $join = $selection->addJoin('authors', 'LEFT JOIN');
        $join->addConstraint('authors.id', 'articles.author_id');
      }
    }

We can now use as follows:

    foreach ($db->articles->withAuthor()->wherePublished()->limit(10) as $article) {
      print $article->title . " - " . $article->authorName . "\n";
    }

Behind the scenes, this will only execute a single SQL query, left joining authors on articles. Otherwise we would issue a new query for author on each iteration.

Complex queries
---

For complex queries, you can either use the object oriented querying api, or if you prefer to write your SQL by hand, you can use parameterised queries. If you just want to add to the "where" part, use this format:

    $db->articles->where('status = ?', 'published');

Or if you want to write the entire SQL by your self:

    $db->articles->query("SELECT * FROM articles");

With parameters:

    $db->articles->pexecute("SELECT * FROM articles WHERE status = :status", array(':status' => 'published'));

Query API
---

pdoext has an object oriented query building api, that can be used for constructing complex queries with. The benefit of doing this, over writing SQL by hand, is that it's fairly easy to incrementally build up the query. This could be used for creating a query based on user input (Such as a search) and it is also useful from within scopes. The interface is heavily inspired by Hibernate.

The most common usage is to add a criterion (condition). This is done in the following way:

    $selection->addCriterion('name', 'Jim'); // WHERE name = 'Jim'

`addCriterion` takes a third parameter, which is the comparison operator. So you can do:

    $selection->addCriterion('name', 'Jim', '!='); // WHERE name != 'Jim'

If you want to compare two fields against each other, rather than field to value, use `addConstraint` instead:

    $selection->addCriterion('name', 'first_name'); // WHERE name = first_name

This is mostly useful when doing joins. You can join a table like this:

    $selection->addJoin('other_table'); // JOIN other_table

`addJoin` returns a join object, where you can add criteria to. Using `addConstraint` from above, this is a typical join:

    $join = $selection->addJoin('authors', 'LEFT JOIN');      // LEFT JOIN authors
    $join->addConstraint('authors.id', 'articles.author_id'); // ON authors.id = articles.author_id

There are more options available - Have a look at the tests and sources.

CRUD
===

The tablegateway provides functions to **insert**, **update** and **delete** on a single row. You can pass an associative array or a record as argument to these functions.

To **insert**:

    $article_id = $db->articles->insert(array('name' => "Jim"));

When **updating**, the *primary key* value from the first argument is used:

    // Rename to John where id = 42
    $db->articles->update(array('id' => 42, 'name' => "John"));

You can optionally pass a second argument with the conditions for the update:

    // Rename all Jim's to John
    $db->articles->update(array('name' => "John"), array('name' => "Jim"));

For completeness' sake, here's how to **delete** a row:


    // Delete record with id = 42
    $db->articles->delete(array('id' => 42));

Validations
---

Validations are callback functions that check the validity of a record, before performing CRUD operations. To use validations, implement them on your custom tablegateway:

    class ArticlesGateway extends pdoext_TableGateway {
      protected function validate($data) {
        if (!preg_match('~^[A-Z][a-zA-Z]+$~', $data->name)) {
          $data->_errors[] = "You must enter a valid name";
        }
      }
    }

The `insert` and `update` operations will not proceed if any errors are present for a record. If you only want a validation to run for either of **insert** or **update**, use the specialised versions:


    class ArticlesGateway extends pdoext_TableGateway {
      // Will not run on UPDATE
      protected function validateInsert($data) {
        if (!preg_match('~^[A-Z][a-zA-Z]+$~', $data->name)) {
          $data->_errors[] = "You must enter a valid name";
        }
      }
    }

Logging
===

The connection class has support for logging all SQL to a file. This is mostly useful during development, for debugging and for performance tuning. To enable logging, just call `setLogging` on the connection object:

    $db->setLogging('/var/log/pdoext_queries.log');

If you are running php from a cli, you may want to have the output echoed out there. Just call `setLogging` without any arguments, and it will write to **stdout**.

You can optionally specify `log_time`. Only queries that are slower than this value will be logged. This can be used to single out those performance bottlenecks in you code. Eg.:

    $db->setLogging('/var/log/pdoext_slow.log', 0.5);

In this case only queries that take more than half a second will be logged.

The log will show where the query was initiated from. This is done by inspecting the callstack and finding the first class that isn't part of pdoext. It will usually make it easier to narrow down where a call came from. Each logline also contains a 6-character hash, which is unique for the process. This allows you to follow loglines even when there are concurrent requests being processed.
