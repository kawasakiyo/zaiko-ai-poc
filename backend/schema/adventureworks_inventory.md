# AdventureWorks 在庫関連スキーマ（Claude へ渡すコンテキスト）

対象DBは Microsoft AdventureWorks (OLTP)。方言は **T-SQL (Microsoft SQL Server)**。
在庫分析でよく使う主要テーブルと列を示す。スキーマ修飾名（`Production.Product` 等）を必ず使うこと。

## Production.Product（製品マスタ）
- ProductID (INT, PK)
- Name (NVARCHAR) 製品名
- ProductNumber (NVARCHAR) 製品番号
- MakeFlag (BIT) 自社製造かどうか
- SafetyStockLevel (SMALLINT) 安全在庫レベル
- ReorderPoint (SMALLINT) 発注点
- StandardCost (MONEY) 標準原価
- ListPrice (MONEY) 定価
- ProductSubcategoryID (INT, FK → Production.ProductSubcategory) ※NULLあり
- SellStartDate / SellEndDate / DiscontinuedDate (DATETIME) 販売期間・廃番日

## Production.ProductInventory（在庫数量：製品×保管場所）
- ProductID (INT, FK → Production.Product)
- LocationID (SMALLINT, FK → Production.Location)
- Shelf (NVARCHAR) 棚
- Bin (TINYINT) ビン
- Quantity (SMALLINT) 在庫数量
- （PK は ProductID + LocationID。1製品が複数ロケーションに分散する）

## Production.Location（保管場所マスタ）
- LocationID (SMALLINT, PK)
- Name (NVARCHAR) 場所名
- CostRate (SMALLMONEY) 時間あたりコスト
- Availability (DECIMAL) 稼働可能時間

## Production.ProductSubcategory（サブカテゴリ）
- ProductSubcategoryID (INT, PK)
- ProductCategoryID (INT, FK → Production.ProductCategory)
- Name (NVARCHAR)

## Production.ProductCategory（カテゴリ）
- ProductCategoryID (INT, PK)
- Name (NVARCHAR)

## 需要・仕入れの参考テーブル（必要に応じて JOIN）
- Sales.SalesOrderDetail(ProductID, OrderQty, UnitPrice, ...) 販売実績＝需要の目安
- Purchasing.PurchaseOrderDetail(ProductID, OrderQty, ReceivedQty, StockedQty, ...) 仕入実績
- Purchasing.ProductVendor(ProductID, BusinessEntityID, AverageLeadTime, StandardPrice, ...) 仕入先と調達リードタイム

## よく使う分析観点
- 在庫総量: `SUM(pi.Quantity)` を ProductID で集約
- 安全在庫割れ: 製品ごとの在庫合計 < Product.SafetyStockLevel、または < ReorderPoint
- カテゴリ別在庫: ProductInventory → Product → ProductSubcategory → ProductCategory を JOIN
- ロケーション別在庫: ProductInventory → Location を JOIN

## 生成ルール（厳守）
- **必ず単一の SELECT 文のみ**を生成する（INSERT/UPDATE/DELETE/DROP/ALTER/EXEC/MERGE/INTO などは禁止）。
- セミコロンで複数文に分けない。
- 大量行を避けるため、適切に `TOP (N)` と `ORDER BY` を付ける。
- 列には分かりやすい別名（AS）を付ける。
- T-SQL 構文（`TOP`, `ISNULL`, `GETDATE()` 等）を用いる。
