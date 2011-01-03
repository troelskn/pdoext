About
===

**pdoext** is a database abstraction layer for php. Its main features are **zero-configuration** and an **elegant api**.

**pdoext** extends **[pdo](http://www.php.net/manual/en/class.pdo.php)** and adds missing functionality, such as **logging** and **introspection** ; convenience functionality such as **quoting of fields** and assertion of **transactions** ; as well as provides some workarounds for compatibility problems (mainly with [sqlite](http://www.sqlite.org/))

Most notably, it provides a **tablegateway**, that abstracts most of the rudimentary SQL out in an intuitive and readable interface. Scroll down for examples.

It is *not* a full-blown ORM; You still need to understand the underlying database to use it efficiently. This helps to *keep the complexity down*, making pdoext relatively simple to comprehend and extend. In particular, *pdoext doesn't manage* **object identity** or **inheritance**. Nor does it isolate your application code completely from the **relational paradigm** of databases.

Connection
===

The most common way to use pdoext is through the connection manager. This only allows you to have one active database connection at a time, but that is usually not a problem. To use, specify the following information some where in your application:

    $GLOBALS['pdoext_connection']['dsn'] = "mysql:dbname=testdb;host=127.0.0.1";
    $GLOBALS['pdoext_connection']['username'] = "root";
    $GLOBALS['pdoext_connection']['password'] = "secret";

The parameters are the same as given to the constructor of a [pdo connection](http://www.php.net/manual/en/function.PDO-construct.php).

Once this has been set, you can grab the gloabally shared connection through `pdoext()`.

Table Gateway
===

The table gateway gives you access to simple **CRUD operations** and to querying for rows. It returns rows in an **active record** style wrapper, that can be extended in user land code. Here's how you would typically use the tablegateway in an application:

    foreach (pdoext()->articles->whereStatusIs('published') as $article) {
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

    $selection = pdoext()->users->whereNameLike('jim%')->paginate($page_number);
    echo "Viewing page " . $selection->currentPage() . " of " . $selection->totalPages() . "\n";
    foreach ($selection as $user) {
      echo "id: " . $user->id . ", name: " . $user->name . "\n";
    }

Fetch single record
---

If you expect one, and only one, record from a query, you can prepend `->one()` to the selection, to fetch the first result. It will throw an exception if there are more than one rows in the result. Eg.:

    $jim = pdoext()->users->whereNameIs('jim')->one();

Chaining conditions
---

You can apply as many conditions as you wish to a query:

    $selection = pdoext()->users->whereNameLike('jim%');
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
        return time() - $this->created_at;
      }
      function setTitle($title) {
        $this->_data['title'] = $title;
        $this->_data['slug'] = preg_replace('[^a-z]', '-', strtolower($title));
      }
    }

Foreign keys
---

If a table defines any foreign keys, you can access them on a record. For example:

    $article = pdoext()->articles->whereTitleIs("Lorem Ipsum")->one();
    $author = $article->author();

It also works the other way:

    $articles = $author->articles();

Note that *no attempt is done at managing identity of rows*. Each time you call these methods, a new query is executed against the database. In other words:

    $authorOne = $article->author();
    $authorTwo = $article->author();
    assert($authorOne !== $authorTwo); // yields true

Likewise, you can't assign an object directly:

    // NOTE: Won't work!
    $author = pdoext()->authors->whereNameIs("Jim")->one();
    $article->author = $author;

Please understand that *this is by design*, as it spares us from a world of complexity related to the [object-relational impedance mismatch](http://en.wikipedia.org/wiki/Object-relational_impedance_mismatch). If you want this kind of functionality, use a full ORM, such as [Doctrine](http://www.doctrine-project.org/).

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

    foreach (pdoext()->articles->wherePublished()->limit(10) as $article) {
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

    foreach (pdoext()->articles->withAuthor()->wherePublished()->limit(10) as $article) {
      print $article->title . " - " . $article->author_name . "\n";
    }

Behind the scenes, this will only execute a single SQL query, left joining authors on articles. Otherwise we would issue a new query for author on each iteration.

Complex queries
---

For complex queries, you can either use the object oriented querying api, or if you prefer to write your SQL by hand, you can use parameterised queries. If you just want to add to the "where" part, use this format:

    pdoext()->articles->where('status = ?', 'published');

Or if you want to write the entire SQL by your self:

    pdoext()->articles->query("SELECT * FROM articles");

With parameters:

    pdoext()->articles->pexecute("SELECT * FROM articles WHERE status = :status", array(':status' => 'published'));

CRUD
===

The tablegateway provides functions to **insert**, **update** and **delete** on a single row. You can pass an associative array or a record as argument to these functions.

To **insert**:

    $article_id = pdoext()->articles->insert(array('name' => "Jim"));

When **updating**, the *primary key* value from the first argument is used:

    // Rename to John where id = 42
    pdoext()->articles->update(array('id' => 42, 'name' => "John"));

You can optionally pass a second argument with the conditions for the update:

    // Rename all Jim's to John
    pdoext()->articles->update(array('name' => "John"), array('name' => "Jim"));

For completeness' sake, here's how to **delete** a row:


    // Delete record with id = 42
    pdoext()->articles->delete(array('id' => 42));

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

The connection class has support for logging all SQL to a file. This is mostly useful during development, for debugging and for performance tuning. To enable logging, call `SetLogging` on the connection:

    pdoext()->setLogging('/var/log/pdoext.log');

If you are running php from a cli, you may want to have the output echoed out there. Just call `setLogging` without any arguments, and it will write to **stdout**.

You can optionally pass a second argument which is a time offset. Only queries that are slower than this value will be logged. This can be used to single out those performance bottlenecks in you code. Eg.:

    pdoext()->setLogging('/var/log/pdoext_slow.log', 0.5);

The log will show where the query was initiated from. This is done by inspecting the callstack and finding the first class that isn't part of pdoext. It will usually make it easier to narrow down where a call came from. Each logline also contains a 6-character hash, which is unique for the process. This allows you to follow loglines even when there are concurrent requests being processed.
