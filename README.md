[![Build Status](https://travis-ci.org/EVODelavega/mysql-diff.svg?branch=master)](https://travis-ci.org/EVODelavega/mysql-diff)

# MySQL diff tool

This tool enables you to automatically generate the queries you need to alter an outdated schema to more closely resemble another version.
If you have, for example, an old DB dump that you want to use, but the DB schema has been changed too much for your application to use it, you can import the old dump into a new database, and then use this tool to add missing tables, indexes and alter column definitions where needed.

## New command

The previous command (`db:diff`) compared tables as stand-alone entities. This is, of course, wrong: A table is part of a collection of tables (databases), and can depend on other tables (relational tables), dropping a table can only work if foreign key constraints are dropped from other tables, and new tables can only be created if they reference existing tables.
To that end, I've added a generic `Database` class that resolves table dependencies and orders the queries in such a way that create statements will work more reliably.
The old command is left as-is, but this tool now has a new `db:compare` command which is the recommended way to compare databases.

### How to use:

It's a simple symfony-based command, and is used pretty much exactly the same as the old `db:diff` command, with a few extra bells and whistles:

```bash
./command.php db:compare -b old_schema -t new_schema
```

This will connect to a DB server on localhost, using the default user (`root`). The command will prompt you for the password, you'll then be given a choice of things to compare:

```
[0] create
[1] alter
[2] constraints
[3] drop
```

You can choose one or more tasks by entering the tasks as a comma-separated list (e.g. 0,1,3 to create missing tables, generate ALTER TABLE queries and DROP TABLE queries for old tables that don't exist in the target DB). Pressing enter selects the default tasks (0, 1 - or create and alter).
After the neccessairy queries have been generated, you'll be asked if you want to quit, entering _"n"_ and pressing enter will let you choose additional tasks. Selecting the same task twice will result in the tool saying something along the lines of _"Skipping task X, already done..."_.
Once you're all done, confirm you want to quit. You'll be prompted for a file path to a file (relative to PWD) to which the tool should write the queries, and whether or not you want to truncate or append the output to that file. After the command terminates, you can simply look at the queries (ie: `vim out_file.sql`).

### New features:

**Interactive-flag**

The command now has an `-i` or `--interactive` flag, which allows you to cross-check new tables with existing ones. If an existing table defines the same foreign keys, it's likely that the table is not really new, but rather that it should be renamed. If several tables are found that might be eligible for renaming, you'll be able to tell which table should be renamed (if any).

If tables might require renaming, and the command is not running interactively, the default behaviour is to create a new table if several possible _"renames"_ have been found. If only one table matches, that table is renamed by default.

Much like the standard symfony verbosity flag, there are 3 levels of interaction, which can be set using either the short flag (`-i|ii|iii`) or passing numeric values to the long option name (`--interactive=3`). If you set the interaction level to `-ii`, you will be able to review, skip, comment out or rewrite `alter` and `drop` statements. The highest verbosity level allows you to do the same thing for all changes (ie: you'll be prompted for `contraints` and `create` statements, too). The highest interaction level assumes you know what you're doing, and is therefore the only level at which you are asked if you want to skip an entire set of changes (eg: _"Do you wish to skip all 123 queries for section alter? (default false)"_). This level of interaction might be useful in cases where the tool gets caught out by field name-changes:

```SQL
CREATE TABLE foo (
   id int(11) unsigned NOT NULL AUTO_INCREMENT,
   bar VARCHAR(255) DEFAULT NULL,
   field2 text
)Engine=InnoDB;
-- vs
CREATE TABLE foo (
   id int(11) unsigned NOT NULL AUTO_INCREMENT,
   bar VARCHAR(255) DEFAULT NULL,
   field3 text -- Field changed name
)Engine=InnoDB;
```

Comparing these tables currently results in an `ALTER` query like:

```SQL
ALTER TABLE foo
  ADD COLUMN field3 VARCHAR(255) text,
  DROP COLUMN field2;
```

In those cases, it can be worthwhile to actually write a your own query:

```SQL
ALTER TABLE foo
   CHANGE COLUMN field2 field3 VARCHAR(255) text;
```

If you're unsure about the query, or you want to rewrite it at a later time, you can write the generated statement to the output file but have in commented out.

**Similarity**

Tables can now be compared in order to generate a percentage of similarity. This is, of course, nowhere near accurate, but it can give you a rough idea of how similar two tables are. The algorithm that computes the similarity of two tables is as follows:

1. Fields (The similarity percentage of fields counts double)
    - Count the number of fields in both tables, use the highest field-count as base (ie: t1 has 4 fields, t2 has 6 -> use 6 as base)
    - Count the number of _shared_ fields (by name) (ie: t1 defines 3 fields with the same name as t2 -> 3)
    - compute the similarity percentage: (3/6)\*100 == 50% - set asside for later
2. Indexes
    - Use the highest index-count as base (cf Fields: t1 defines 1 index, t2 defines 3 -> use 3 as base)
    - If neither table defines any indexes, the similarity percentage is 50% (as a sort-of neutral value)
    - If the index does not exist in the target table, set count value to 0
    - If it does exist, start with count-value being _1_, and compare index fields. for each field not present in both indexes, halve the count-value
    - add each count-value to a running total
    - return the similarity percentage: (count-value/base)\*100
    - eg: if t1's index is not defined in t2, the similarity will be 0: (0/3)\*100
    -     if, however, both tables contain the index, but 1 field is not shared, the similarity will be: (.5/3)\*100 ~= 16.67%
3. Primary keys:
    - If neither of the tables defines a PK, the similarity percentage used is 80% when computing the overal similarity
    - If the target table defines a PK, but the current table doesn't, the current table is checked for any missing fields
    - If all fields a possible new PK are already defined, the similarity returned is 50%
    - If some fields are missing, the similarity is equal to the number of fields presently defined multiplied by 10 (ie: a PK of 2 fields, 1 is defined -> 10% similarity)
    - If the current table defines a PK, but the target table doesn't, the similarity is a hard-coded .1%, on the basis that dropping a PK is highly unlikely
    - IF both tables define a PK, the number of fields in that PK is compared
    - Using the current PK, each field in that PK is checked if it is contained within the target PK, and if it is defined in the target table
    - The same steps are repeated, this time using the target PK
    - All of these checks have equal weight: in case of 2 PK's, each containing 1 field, this means 5 checks are performed, with base 5 to compute the percentage
    - lastly the current PK calls `isEqual` and is passed the target PK as argument. If that call returns true, the base is added to the similarity total
    - The base is multiplied by two (ensuring `isEqual` determines 50% of the overall similarity percentage)
    - The similarity is calculated as ever: (similarityCount/base)\*100
4. Overall similarity:
    - The overall similarity is the sum of all 3 similarity percentages (but Fields value is multiplied by 2), divided by 400

-----

## How to use

It's a simple CLI tool. To set it up, simply run `composer install -o` in the project root. To run the tool, execute the command.php tool: `./command.php db:diff -h`
The output of the help message should be enough to work out how to use the tool, but still: the typical command you'll use to generate the queries would look something like this:

```bash
./command.php db:diff -H localhost -u dbuser -p dbpass -b old_schema -t new_schema -c -a
# or
./command.php db:diff --host=localhost --username=dbuser --password=dbpass --base=old_schema --target=new_schema --alter --create
```

The output will be a series of queries that update the `old_schema` DB to more closely resemble the `new_schema` layout. Queries to drop tables that are no longer in use can also be generated by adding the `--drop` flag (or `-d` for short).

When generating `--alter` queries, you can add the `-F` or `--fks` flag to have the tool check foreign key constraints. If an existing foreign key uses a different field in the target schema, this is seen as an indication of a field being renamed. Instead of adding the field using `ADD COLUMN`, a `CHANGE COLUMN` line will be added, using the field definition found in the new schema.

By default, columns that exist in the old schema, but not in the new one are left as they are. If you want to delete old fields, you can use the `--purge` or `-P` flag to add `DROP COLUMN` lines to the output.
Tables that are no longer part of the target schema are also left alone, but there's a `--drop` (`-d`) flag to generate `DROP TABLE` queries, too.

### How it works

Basically, the tool sets about fetching all of the table names that make up the target schema. One by one, it then checks to see if the base schema has a table with the same name. If not, the create statement from the target schema is added to the list of queries. If it does, both create statements are parsed, and the parts that make up the table are compared in the following order:

- Foreign keys (if `-F` flag was passed)
- Missing fields
- Redundant fields (if `-P` flag was passed)
- Primary keys (compared/added/dropped if needed)
- Indexes (add missing indexes, or redefine existing ones)

Next up, tables that are not found in the target schema are dropped


