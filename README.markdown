About
===

**pdoext** is a database abstraction layer for php. Its main features are **zero-configuration** and an **elegant api**.

It is *not* a full-blown ORM; You still need to understand the underlying database to use it efficiently. This helps to *keep the complexity down*, making pdoext relatively simple to comprehend and extend. In particular, *pdoext doesn't manage* **object identity** or **inheritance**. Nor does it isolate your application code completely from the **relational paradigm** of databases.

**pdoext** extends **[pdo](http://www.php.net/manual/en/function.PDO-construct.php)** and adds missing functionality, such as **logging** and **introspection** ; convenience functionality such as **quoting of fields** and assertion of **transactions** ; as well as provides some workarounds for compatibility problems (mainly with [sqlite](http://www.sqlite.org/))

Table Gateway
===

The table gateway gives you access to simple **CRUD operations** and to querying for rows. It returns rows in an **active record** style wrapper, that can be extended in user land code. Here's how you would typically use the tablegateway in an application:


    foreach ($db->articles->whereStatusIs('published') as $article) {
      print $article->title . " - " . $article->author()->name . "\n";
    }

No configuration and no userland code is required to use this. **pdoext** will use introspection to figure out how your tables are linked together, using [foreign key](http://en.wikipedia.org/wiki/Foreign_key) constraints.

Pagination
---

Tablegateways have built-in support for **pagination**:

    $selection = $db->users->whereNameLike('jim%')->paginate($page_number);
    echo "Viewing page " . $selection->currentPage() . " of " . $selection->totalPages() . "\n";
    foreach ($selection as $row) {
      echo "id: " . $row->id . ", name: " . $row->name . "\n";
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

    $article = $db->articles->whereTitleIs("Lorem Ipsum")->one();
    $author = $article->author();

It also works the other way:

    $articles = $author->articles();

Note that *no attempt is done at managing identity of rows*. Each time you call these methods, a new query is executed against the database. In other words:

    $authorOne = $article->author();
    $authorTwo = $article->author();
    assert($authorOne !== $authorTwo); // yields true

Likewise, you can't assign an object directly:

    // NOTE: Won't work!
    $article->author = $db->authors->whereNameIs("Jim")->one();

Please understand that *this is by design*, as it spares us from a world of complexity related to the [object-relational impedance mismatch](http://en.wikipedia.org/wiki/Object-relational_impedance_mismatch). If you want this kind of functionality, use a full ORM, such as [Doctrine](http://www.doctrine-project.org/).

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
      print $article->title . " - " . $article->author_name . "\n";
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
