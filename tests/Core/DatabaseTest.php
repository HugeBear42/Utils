<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use HugeBear42\Utils\Core\Database;
use HugeBear42\Utils\Core\ApplicationException;


final class DatabaseTest extends TestCase
{
	public function testSqliteInMemory(): void
	{
		$db=new Database(['type'=>'sqlite', 'connection'=>['path'=>':memory:']]);
		$this->assertSame('sqlite', $db->getType());
		$db->query('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
		$db->query('INSERT INTO items (name) VALUES (:name)', ['name'=>'Müller']);
		$this->assertSame('1', (string)$db->lastInsertId());
		$this->assertSame([['id'=>1, 'name'=>'Müller']], $db->query('SELECT * FROM items')->get());
		$this->assertSame(['id'=>1, 'name'=>'Müller'], $db->query('SELECT * FROM items WHERE id = :id', ['id'=>1])->find());
		$this->assertSame(1, $db->query('UPDATE items SET name = :name', ['name'=>'Rochat'])->rowCount());
	}

	public function testSqliteFileIsKept(): void
	{
		$file=sys_get_temp_dir().DIRECTORY_SEPARATOR.'databasetest_'.uniqid().'.sqlite';
		try
		{
			$db=new Database(['type'=>'sqlite', 'connection'=>['path'=>$file]]);
			$db->query('CREATE TABLE items (name TEXT)');
			$db->query("INSERT INTO items VALUES ('kept')");
			unset($db);
			$db=new Database(['type'=>'sqlite', 'connection'=>['path'=>$file]]);	// A second connection to the same file
			$this->assertSame('kept', $db->query('SELECT name FROM items')->find()['name']);
			unset($db);
		}
		finally
		{
			gc_collect_cycles();
			if(is_file($file))
			{	unlink($file);	}
		}
	}

	public function testSqliteForeignKeysAreEnforced(): void
	{
		$db=new Database(['type'=>'sqlite', 'connection'=>['path'=>':memory:']]);
		$db->query('CREATE TABLE parents (id INTEGER PRIMARY KEY)');
		$db->query('CREATE TABLE children (id INTEGER PRIMARY KEY, parent_id INTEGER REFERENCES parents(id))');
		$this->expectException(PDOException::class);
		$db->query('INSERT INTO children (parent_id) VALUES (99)');		// No parent 99
	}

	public function testSqliteWithoutPathIsRefused(): void
	{
		$this->expectException(ApplicationException::class);
		new Database(['type'=>'sqlite', 'connection'=>[]]);
	}

	public function testUnsupportedTypeIsRefused(): void
	{
		$this->expectException(ApplicationException::class);
		new Database(['type'=>'oracle', 'connection'=>[], 'user'=>'u', 'pass'=>'p']);
	}
}
