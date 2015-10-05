package dbdiff

import (
	"errors"
	"fmt"
	"regexp"
	"strings"
)

type patterns struct {
	generic, fieldDef, fkDef *Regexp //generic is used for table name, PK, index definition etc..
}

//Field This object represents a field/column in a DB table
type Field struct {
	Name, Datatype, DefaultValue, Attributes string
	Nullable, AutoIncrement, IsPK, Signed    bool
}

//A PrimaryKey linked to table, hold pointers to the fields that make up the PK
type PrimaryKey struct {
	Fields map[string]*Field
}

//An Index defined on a table
type Index struct {
	Name   string
	Unique bool
	Fields map[string]*Field
}

//An Fk (Foreign key) defined on a table, references the outer table, too
type Fk struct {
	Name, KeyField, ReferenceTable, ReferenceField string
	Constraints                                    []string //@todo -> implement this!
	References                                     *Table
}

//A Table representation
type Table struct {
	Name, CreateStmt string
	Pk               PrimaryKey
	Fields           map[string]*Field
	Fks              map[string]*Fk
	Indexes          map[string]*Index
	DependantTables  map[string]*Table
	LinkedTables     map[string]*Table
}

//A TableCollection is a DB... a more generic name avoids possible name conflicts
type TableCollection struct {
	Tables        map[string]*Table
	Charset, Name string
}

var p patterns

//GetTablesInCreatableOrder gets list of tables in the correct order to create them, check error for unresolvable creates
func (db *TableCollection) GetTablesInCreatableOrder() ([]*Table, error) {
	todo := len(db.Tables)
	order := make([]*Table, todo)
	var created map[string]bool
	for todo > 0 {
		oldTodo := todo
		for name, tbl := range db.Tables {
			created[name] = true
			//for dName, _ :range tbl.LinkedTables {
			//is what we're doing, which is more readable IMO, but golint complains
			for dName := range tbl.LinkedTables {
				added, ok := created[dName]
				if !ok || !added {
					//dependency not fulfilled
					created[name] = false
					break
				}
			}
			if created[name] == true {
				todo--
			}
		}
		if oldTodo == todo {
			return order, getOrderError(db, created, todo)
		}
	}
	return order, nil
}

func getOrderError(db *TableCollection, created map[string]bool, left int) error {
	missing := make([]string, left)
	missingIdx := 1
	for name, state := range created {
		if state == false {
			tbl, _ := db.Tables[name] //we're sure tbl exists
			depends := make([]string, len(tbl.LinkedTables))
			dependsIdx := 0
			//same as before: for linkName, _ := range tbl.LinkedTables { makes more sense to me, but golint...
			for linkName := range tbl.LinkedTables {
				linkState, ok := created[linkName]
				if !ok || !linkState {
					depends[dependsIdx] = linkName
					dependsIdx++
				}
			}
			if dependsIdx == 0 {
				missing[missingIdx] = fmt.Sprintf("Error resolving %s: UNKNOWN REASON", name)
			} else {
				missing[missingIdx] = fmt.Sprintf("Error resolving %s: missing %s", name, strings.Join(depends, ", "))
			}
		}
	}
	return fmt.Errorf("Table sorting error possible circular references:\n%s", strings.Join(missing, "\n"))
}

//AddTableFromQuery parses CREATE query string & adds table object to collection
func (db *TableCollection) AddTableFromQuery(q string, errOnDuplicate bool) (*Table, error) {
	t, err := NewTableFromQuery(q)
	if err != nil {
		return nil, err
	}
	_, err := db.AddTable(t, errOnDuplicate)
	return t, err
}

//AddTable add pure table instance, sets links defined in FK's up
func (db *TableCollection) AddTable(t *Table, errOnDuplicate bool) (*TableCollection, err) {
	val, ok := db.Tables[t.Name]
	if ok {
		if errOnDuplicate {
			return db, fmt.Errorf("Table %s already exists in DB %s", t.Name, db.Name)
		}
		val.UnlinkTable()
		delete(db.Tables, t.Name)
	}
	for _, fk := range t.Fks {
		if val, ok := db.Tables[fk.ReferenceTable]; ok {
			t.LinkedTables[fk.ReferenceTable] = val
			val.DependantTables[t.Name] = t
			fk.References = val
		}
	}
	db.Tables[t.Name] = t
	return db
}

//LinkAllTables can be called after adding several relational tables, it'll link all FK's and table objects correctly
func (db *TableCollection) LinkAllTables(withIntegrityCheck bool) (*TableCollection, error) {
	for name, tbl := range db.Tables {
		for fkName, fk := range tbl.Fks {
			link, ok := tbl.LinkedTables[fk.ReferenceTable]
			if ok == true {
				if withIntegrityCheck == true {
					if f, ok := link.Fields[fk.ReferenceField]; !ok {
						//should we remove faulty link here?
						return db, fmt.Errorf("FK %s invalid: %s does not exist in reference table %s", fkName, fk.ReferenceField, f.Name)
					}
				}
			} else {
				val, ok := db.Tables[fk.ReferenceTable]
				if !ok {
					return db, fmt.Errorf("Unable to link schema, table %s seems to be missing", fk.ReferenceTable)
				}
				if withIntegrityCheck {
					//check if reference FIELD exists!
					if f, ok := val.Fields[fk.ReferenceField]; !ok {
						return db, fmt.Errorf("FK %s invalid: %s does not exist in reference table %s", fkName, fk.ReferenceField, val.Name)
					}
				}
				tbl.LinkedTables[val.Name] = val
				fk.References = val
				val.DependantTables[tbl.Name] = tbl
			}
		}
	}
	return db, nil
}

//RemoveTable does what is says on the tin, remove via existing pointer
func (db *TableCollection) RemoveTable(t *Table) *TableCollection {
	if _, ok := db.Tables[t.Name]; !ok {
		//not in DB, nothing to remove
		return db
	}
	//remove from db
	delete(db.Tables, t.Name)
}

//RemoveTableByName does what its name suggests
func (db *TableCollection) RemoveTableByName(tName string) (*TableCollection, error) {
	val, ok := db.Tables[tName]
	if !ok {
		return db, fmt.Errorf("Table %s does not exist in DB %s", tName, db.Name)
	}
	return db.RemoveTable(val), nil
}

//NewTableFromQuery create a new Table object, add it to a collection to use properly
func NewTableFromQuery(q string) (*Table, error) {
	var err error
	t := new(Table)
	t.CreateStmt = q
	lines := strings.Split(q, "\n")
	name = p.generic.FindStringSubmatch(lines[0])[1]
	lines = lines[1 : len(lines)-2]

	for i, l := range lines {
		//strip spaces
		l = strings.Trim(l, " ")
		//check what kind of line we're dealing with
		//process and add what we can
		if l[0] == "`" {
			//create field
			field, err := NewFieldFromString(l)
			if err == nil {
				//add field to map
				t.Fields[field.Name] = field
			}
		} else if strings.Contains(l, "PRIMARY KEY") {
			err := t.AddPrimaryKeyFromString(l)
		} else if strings.Contains(l, "CONSTRAINT") {
			fk, err := NewConstraintFromString(l)
			if err == nil {
				t.Fks[fk.Name] = fk
			}
		} else {
			err := t.AddIndexFromString(l)
		}
		if err != nil {
			//check err
			return nil, err
		}
	}
	return t, nil
}

//UnlinkTable Prepare a table for removal by removing links
func (t *Table) UnlinkTable() {
	name := t.Name //shortcut
	for ltName, linked := range t.LinkedTables {
		for fkName, fk := range linked.Fks {
			if fk.References == t {
				//remove FK links, too
				delete(linked.Fks, fkName)
			}
		}
		delete(linked.LinkedTables, name)
	}
	for fkName, fk := range t.Fks {
		if fk.References != nil {
			fk.References = nil //unset all links to this table
		}
	}
}

//AddIndexFromString use part of the Create statement to add an index to a table object
func (t *Table) AddIndexFromString(idx string) error {
	matches := p.generic.FindAllStringSubmatch(idx, -1)
	if len(matches) == 0 || len(matches[0]) != 2 {
		return fmt.Errorf("Unable to reliably parse idx line %s", idx)
	}
	matches = matches[0]
	tIdx = new(Index)
	tIdx.Name = matches[0][1]
	for _, fname := range matches[1:] {
		if val, ok := t.Fields[fname[1]]; ok {
			tIdx[fname[1]] = val
		} else {
			return fmt.Errorf("Index cannot use non-existant field %s", fname[1])
		}
	}
	if strings.Contains(strings.ToLower(idx), "unique") {
		tIdx.Unique = true
	} else {
		tIdx.Unique = false
	}
	t.Indexes[tIdx.Name] = tIdx
	return nil
}

//AddPrimaryKeyFromString add PK object to table instance, based on CREATE substring
func (t *Table) AddPrimaryKeyFromString(pk string) error {
	matches := p.generic.FindAllStringSubmatch(pk, -1)
	if len(matches) < 1 {
		return fmt.Errorf("Failed to extract fields from PK definition %s", pk)
	}
	p := new(PrimaryKey)
	for _, sub := range matches {
		if f, ok := t.Fields[sub]; ok {
			p.Fields[sub] = f
		} else {
			return fmt.Errorf("Unable add PK for table %s, field %s not found", t.Name, sub)
		}
	}
	t.Pk = p
	return nil
}

//NewConstraintFromString returns names only, FK is not linked yet, here!
func NewConstraintFromString(fkStmt string) (*Fk, error) {
	matches := p.fkDef.FindAllStringSubmatch(fkStmt, -1)
	if len(matches) < 1 || len(matches[0]) != 5 {
		return nil, fmt.Errorf("Unable to extract contraint from %s", fkStmt)
	}
	fk := new(Fk)
	fk.Name = matches[0][1]
	fk.KeyField = matches[0][2]
	fk.ReferenceTable = matches[0][3]
	fk.ReferenceField = matches[0][4]
	return fk, nil
}

//NewFieldFromString creates a new Field instance from a string
func NewFieldFromString(fStmt string) (*Field, error) {
	f := new(Field)
	matches := p.fieldDef.FindStringSubmatch(fStmt)
	if len(matches) != 7 {
		return nil, fmt.Errorf("Unable to extract field name and type from %s", fieldDef)
	}
	f.Name = matches[1]
	f.DataType = matches[2]
	f.Signed = true //default signed value,
	f.Attributes = strings.Join(matches[3:], " ")
	lowerAttr := strings.ToLower(f.Attributes)
	if strings.Contains(lowerAttr, "auto_increment") {
		f.AutoIncrement = true
	}
	if strings.Contains(lowerAttr, "not null") {
		f.Nullable = false
	} else {
		f.Nullable = true
	}
	if strings.Contains(lowerAttr, "unsigned") {
		f.Signed = false
	} else {
		f.Signed = true
	}
	if strings.Contains(lowerAttr, "default ") {
		f.DefaultValue = matches[6]
	}
	return f, nil
}

func init() {
	p.generic = regexp.MustCompile("`([^`]+)`")
	p.fieldDef = regexp.MustCompile("^`([^`]+)`\\s+([^\\s]+)\\s*(.*?)\\s*(NOT NULL)?\\s*(DEFAULT\\s+([^,]+)|AUTO_INCREMENT|,)")
	p.FkDef = regexp.MustCompile("`([^`]+)`[^(]+\\(`([^`]+)`[^`]+`([^`]+)`[^(]+\\(`([^`]+)")
}
