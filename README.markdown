pdoext is a database abstraction layer for PHP5, based on PDO.

It is by no means a full blown ORM, and should rather be seen as an extension of PDO.

The library consists of three parts; The connection is a pre-requisite for the other two, but you could use the connection on its own.

The main component is the connection class. This extends from the main [PDO class](http://www.php.net/manual/en/function.PDO-construct.php) and adds some convenience methods as well as patching some bugs.

The rest of the library consists of an object oriented query building API, similar to [Hibernate's Criteria API](http://www.hibernate.org/hib_docs/reference/en/html/querycriteria.html). This allows an elegant way of constructing complex queries dynamically.

There is also a simple table data gateway, for basic CRUD operations.

The table gateway and the query api do not depend on each other, so you can use them separately.

Below are some code samples, to give you an idea about the scope of the library:

Query abstraction:
==

Example 1: Simple select
--

    $db = new pdoext_Connection(...);
    $query = new pdoext_Query("people");
    $query->addCriterion("first_name", "John");
    $db->query($query);

_Results in:_

    select * from `people` where `first_name` = 'John'

Example 2: Select with a left join and various options
--

    $db = new pdoext_Connection(...);
    $query = new pdoext_Query("people");
    $query->addColumn("first_name");
    $query->setLimit(10);
    $query->setOffset(10);
    $join = $query->addJoin("accounts", "LEFT JOIN");
    $sub = $join->addCriterion(new pdoext_query_Criteria("OR"));
    $sub->addConstraint("people.account_id", "accounts.account_id");
    $sub->addCriterion("people.account_id", 28, ">");
    $query->addCriterion('first_name', "John");
    $db->query($query);

_Results in:_

    select `first_name`
    from `people`
    left join `accounts`
    on `people`.`account_id` = `accounts`.`account_id` or `people`.`account_id` > '28'
    where `first_name` = 'John'
    limit 10
    offset 10

Table gateway:
==

_Assuming the following table:_

    CREATE TABLE users (
      id INTEGER,
      name VARCHAR(255)
    )

Example 3: Insert a record
--

    $db->users->insert(array("id" => 42, "name" => "John"));

_The table now contains:_

id | name
-- | ----
42 | John

Example 4: Update a record
--

    $db->users->update(array("name" => "Jim"), array("id" => 42));

_The table now contains:_

id | name
-- | ----
42 | Jim


Example 5: Fetch a single record
--

    $record = $db->users->fetch(array("id" => 42));
    echo "id: " . $record->id . ", name: " . $record->name . "\n";

_Prints out:_

    id: 42, name: Jim

Example 6: Delete a record
--

    $db->users->delete(array("id" => 42));

Example 7: Select through gateway
--

    $db->users->whereNameLike('jim%');

_Results in:_

    select *
    from `users`
    where `name` LIKE 'jim%'

Example 8: Paginate query
--

    $selection = $db->users->whereNameLike('jim%')->paginate($page_number);
    echo "Viewing page " . $selection->currentPage() . " of " . $selection->totalPages() . "\n";
    foreach ($selection as $row) {
      echo "id: " . $row->id . ", name: " . $row->name . "\n";
    }

More examples in the [test-suite](https://github.com/troelskn/pdoext/tree/master/test)
