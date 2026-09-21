# 08. MongoDB 7

## Навіщо ця тема

MongoDB — документо-орієнтована СУБД: замість рядків у таблицях вона зберігає документи у форматі BSON (бінарний JSON) у колекціях. Це зручно, коли структура даних змінна або природно вкладена: профіль користувача з адресами, замовлення зі списком позицій, каталог із різними наборами характеристик.

Практична цінність теми: MongoDB не вимагає JOIN-ів для читання вкладених даних і дозволяє швидко почати роботу без міграцій схеми. Водночас вона вимагає іншого мислення: замість нормалізації — вкладення або посилання, замість SQL — мова запитів MQL та агрегаційний конвеєр. У цьому розділі всі приклади виконуються на базах `learn` (навчальні колекції) та `shop_big` (великий обсяг для перевірки індексів).

Порада: приклади, які вставляють, оновлюють або видаляють документи, безпечніше виконувати в колекціях бази `shop_big` — її легко перегенерувати командою `./manage.sh seed`. Колекції `learn.students` і `learn.courses` містять лише по кілька документів, і після експериментів їх доведеться відновлювати вручну.

## Підключення

Консоль `mongosh`:

```bash
docker exec -it learn-mongo mongosh -u root -p student --authenticationDatabase admin
```

Після входу перемкніться на навчальну базу:

```javascript
use learn
db.getName()
show collections
db.students.countDocuments()
```

З хоста, якщо встановлено mongosh:

```bash
mongosh "mongodb://root:student@127.0.0.1:27017/learn?authSource=admin"
```

Веб-інтерфейс Mongo Express:

```text
http://localhost:8082
```

Mongo Express працює без пароля (basic auth вимкнено). У лівій колонці — список баз (`learn`, `shop_big`, `admin`), далі колекції та документи. Для перегляду документа достатньо клацнути його; для запиту — вкладка Query, де приймається звичайний JSON-фільтр, наприклад `{"grade": {"$gte": 85}}`. Агрегаційний конвеєр у Mongo Express не редагується — його зручніше виконувати в `mongosh`.

## Модель даних

- **Документ** — набір пар «поле: значення» у фігурних дужках. Максимальний розмір — 16 МБ.
- **Колекція** — аналог таблиці, але без фіксованої схеми: сусідні документи можуть мати різні поля.
- **База даних** — набір колекцій. `use learn` створює базу неявно при першому записі.
- **Поле `_id`** — обов'язковий унікальний ідентифікатор. Якщо не вказати його, драйвер згенерує `ObjectId`.

Типи BSON: `string`, `int`, `long`, `double`, `decimal`, `bool`, `date`, `null`, `array`, `object`, `ObjectId`, `binData`.

```javascript
db.students.insertOne({
  name: 'Петро Гриценко',
  email: 'petro@example.com',
  city: 'Полтава',
  courses: ['SQL'],
  grade: 88.5,
  active: true,
  registered_at: new Date(),
  meta: { source: 'manual', verified: false }
})
```

## Синтаксис

```javascript
// читання
db.колекція.find(<фільтр>, <проєкція>).sort(<сортування>).limit(<n>).skip(<m>)
db.колекція.findOne(<фільтр>)

// запис
db.колекція.insertOne(<документ>)
db.колекція.insertMany([<документи>])
db.колекція.updateOne(<фільтр>, { $set: { ... }, $inc: { ... }, $push: { ... } })
db.колекція.updateMany(<фільтр>, <оператори>)
db.колекція.replaceOne(<фільтр>, <новий документ>)
db.колекція.deleteOne(<фільтр>)
db.колекція.deleteMany(<фільтр>)

// індекси
db.колекція.createIndex({ <поле>: 1 }, { unique: true, name: '<назва>' })
db.колекція.getIndexes()
db.колекція.dropIndex('<назва>')

// агрегація
db.колекція.aggregate([
  { $match: { ... } },
  { $group: { _id: '$поле', count: { $sum: 1 } } },
  { $sort: { count: -1 } }
])
```

Службові команди оболонки:

```javascript
use learn                 // перемкнутися на базу
show collections          // список колекцій
show dbs                  // список баз
db.stats()                // статистика бази
```

## CRUD: створення, читання, оновлення, видалення

### Створення

```javascript
db.students.insertOne({ name: 'Новий Студент', email: 'new@example.com', city: 'Рівне', courses: [], grade: 0 })

db.students.insertMany([
  { name: 'Ірина Савченко', email: 'iryna@example.com', city: 'Луцьк', courses: ['SQL'], grade: 91 },
  { name: 'Богдан Лисенко', email: 'bohdan@example.com', city: 'Черкаси', courses: ['MongoDB'], grade: 74 }
])
```

`insertMany` без опції `ordered: false` зупиняється на першій помилці, решта документів не вставляється.

### Читання

```javascript
db.students.find()                                  // усі документи
db.students.findOne({ email: 'olena@example.com' }) // один документ
db.students.find({ city: 'Київ' })                  // фільтр за рівністю

db.students.find(
  { grade: { $gte: 80 } },
  { name: 1, city: 1, grade: 1, _id: 0 }            // проєкція: що саме повертати
)

db.students.find().sort({ grade: -1 }).limit(2).skip(1)
db.students.countDocuments({ city: 'Київ' })
db.students.distinct('city')
```

### Оновлення

```javascript
db.students.updateOne(
  { email: 'petro@example.com' },
  { $set: { grade: 90 }, $push: { courses: 'MongoDB' } }
)

db.students.updateMany(
  { city: 'Київ' },
  { $inc: { grade: 1 } }
)

db.students.updateOne(
  { email: 'new@example.com' },
  { $setOnInsert: { registered_at: new Date() }, $set: { city: 'Тернопіль' } },
  { upsert: true }                                   // вставити, якщо не знайдено
)

db.students.replaceOne(
  { email: 'bohdan@example.com' },
  { name: 'Богдан Лисенко', email: 'bohdan@example.com', city: 'Черкаси', courses: ['MongoDB', 'SQL'], grade: 76 }
)
```

### Видалення

```javascript
db.students.deleteOne({ email: 'new@example.com' })
db.students.deleteMany({ grade: { $lt: 50 } })
db.students.drop()          // видалити всю колекцію
```

## Вкладені документи та масиви

Для демонстрації вкладених структур створіть навчальну колекцію:

```javascript
db.enrollments.insertMany([
  {
    student: 'olena@example.com',
    scores: [
      { course: 'SQL', score: 90 },
      { course: 'MongoDB', score: 95 }
    ]
  },
  {
    student: 'ihor@example.com',
    scores: [
      { course: 'SQL', score: 82 }
    ]
  }
])
```

Доступ до вкладених полів — через крапкову нотацію:

```javascript
db.enrollments.find({ 'scores.course': 'SQL' })

db.enrollments.updateOne(
  { student: 'ihor@example.com' },
  { $push: { scores: { course: 'PostgreSQL', score: 88 } } }
)

db.enrollments.updateOne(
  { student: 'ihor@example.com' },
  { $pull: { scores: { course: 'SQL' } } }
)

db.enrollments.updateOne(
  { student: 'olena@example.com', 'scores.course': 'SQL' },
  { $set: { 'scores.$.score': 93 } }                 // позиційний оператор $
)
```

Операції з масивами в `students`:

```javascript
db.students.updateOne({ email: 'maria@example.com' }, { $addToSet: { courses: 'SQL' } })
db.students.find({ courses: { $size: 1 } })
db.students.find({ courses: { $all: ['SQL', 'MongoDB'] } })
```

## Оператори запитів

| Оператор | Призначення | Приклад |
|---|---|---|
| `$eq`, `$ne` | рівність / нерівність | `{ city: { $ne: 'Київ' } }` |
| `$gt`, `$gte`, `$lt`, `$lte` | порівняння | `{ grade: { $gt: 85 } }` |
| `$in`, `$nin` | належність до списку | `{ city: { $in: ['Київ', 'Львів'] } }` |
| `$and`, `$or`, `$not` | логічні комбінації | `{ $or: [{ city: 'Київ' }, { grade: { $gt: 90 } }] }` |
| `$exists` | наявність поля | `{ phone: { $exists: false } }` |
| `$regex` | регулярний вираз | `{ email: { $regex: '@example\\.com$' } }` |
| `$size` | довжина масиву | `{ courses: { $size: 2 } }` |
| `$all` | масив містить усі елементи | `{ courses: { $all: ['SQL', 'MongoDB'] } }` |
| `$elemMatch` | елемент масиву задовольняє кілька умов | `{ scores: { $elemMatch: { course: 'SQL', score: { $gte: 85 } } } }` |
| `$type` | тип BSON | `{ grade: { $type: 'double' } }` |

Приклади:

```javascript
db.students.find({ grade: { $gt: 80, $lte: 95 } })
db.students.find({ city: { $in: ['Київ', 'Одеса', 'Львів'] } })
db.students.find({ $and: [{ grade: { $gte: 80 } }, { courses: 'SQL' }] })
db.students.find({ $or: [{ city: 'Київ' }, { grade: { $gt: 90 } }] })
db.students.find({ name: { $regex: '^[ОІ]', $options: 'i' } })

db.enrollments.find({
  scores: { $elemMatch: { course: 'SQL', score: { $gte: 85 } } }
})
```

Важлива різниця: `{ 'scores.course': 'SQL', 'scores.score': 85 }` перевіряє поля незалежно й може знайти документ, де `SQL` має оцінку 90, а оцінка 85 належить іншому курсу. `$elemMatch` вимагає, щоб умови виконувалися для одного й того самого елемента масиву.

## Індекси та explain

Без індексу MongoDB сканує всю колекцію (`COLLSCAN`). Індекс перетворює пошук на `IXSCAN`.

```javascript
db.students.createIndex({ email: 1 }, { unique: true, name: 'email_unique' })
db.students.createIndex({ city: 1, grade: -1 }, { name: 'city_grade' })
db.students.createIndex({ courses: 1 }, { name: 'courses_multi' })   // multikey для масиву
db.students.createIndex({ 'scores.course': 1 }, { name: 'scores_course' })

db.students.getIndexes()
db.students.dropIndex('courses_multi')
db.students.dropIndexes()
```

Перевірка плану виконання:

```javascript
db.students.find({ grade: { $gte: 85 } }).explain('executionStats')
```

У результаті дивіться:

- `queryPlanner.winningPlan.stage` — `COLLSCAN` (погано на великих колекціях) або `IXSCAN` (добре);
- `executionStats.totalDocsExamined` — скільки документів реально переглянуто;
- `executionStats.totalKeysExamined` — скільки записів індексу переглянуто;
- `executionStats.nReturned` — скільки документів повернено;
- `executionStats.executionTimeMillis` — час виконання.

Ефективний індекс має `totalDocsExamined`, близьке до `nReturned`.

### Велика база shop_big

База `shop_big` містить колекції `orders` (100 000 документів), `customers` (10 000) і `products` (1 000) і призначена для вимірювання продуктивності. Структура документів:

```text
customers: { _id: 1, name, email, city, createdAt }
products:  { _id: 1, title, category, price, stock }
orders:    { _id: 1, customerId, status, items: [ { productId, title, price, quantity } ], total, orderedAt }
```

Спершу перегляньте документи та порахуйте їх:

```javascript
use shop_big
db.orders.findOne()
Object.keys(db.orders.findOne())
db.orders.countDocuments()
db.customers.countDocuments()
db.products.countDocuments()
```

Порівняйте план до і після створення індексу:

```javascript
db.orders.find({ customerId: 42 }).explain('executionStats').executionStats

db.orders.createIndex({ customerId: 1 }, { name: 'orders_customer' })
db.orders.createIndex({ status: 1, orderedAt: -1 }, { name: 'orders_status_date' })

db.orders.find({ customerId: 42 }).explain('executionStats').executionStats
db.orders.getIndexes()
```

Додатковий приклад для масиву `items`: індекс за полем усередині масиву перетворює пошук позицій на `IXSCAN`:

```javascript
db.orders.createIndex({ 'items.productId': 1 }, { name: 'orders_item_product' })
db.orders.find({ 'items.productId': 25 }).explain('executionStats').executionStats
```

До створення індексу `totalDocsExamined` дорівнює розміру колекції, після — кількості знайдених документів. Заміри часу зручно робити через `db.orders.find(...).explain('executionStats').executionStats.executionTimeMillis`.

## Агрегаційний конвеєр

Конвеєр — масив стадій, кожна з яких перетворює потік документів. Основні стадії: `$match`, `$project`, `$group`, `$sort`, `$limit`, `$skip`, `$unwind`, `$lookup`, `$count`, `$addFields`.

### Фільтрація та групування

```javascript
db.students.aggregate([
  { $match: { grade: { $gte: 80 } } },
  { $group: {
      _id: '$city',
      avgGrade: { $avg: '$grade' },
      maxGrade: { $max: '$grade' },
      students: { $sum: 1 }
  } },
  { $sort: { avgGrade: -1 } }
])
```

`$match` бажано ставити першою стадією: якщо є відповідний індекс, MongoDB відфільтрує документи до групування.

### Розгортання масиву: $unwind

```javascript
db.students.aggregate([
  { $unwind: '$courses' },
  { $group: { _id: '$courses', students: { $sum: 1 } } },
  { $sort: { students: -1, _id: 1 } }
])
```

### Об'єднання колекцій: $lookup

```javascript
db.students.aggregate([
  { $unwind: '$courses' },
  { $lookup: {
      from: 'courses',
      localField: 'courses',
      foreignField: 'code',
      as: 'course_info'
  } },
  { $unwind: '$course_info' },
  { $group: {
      _id: '$course_info.title',
      hours: { $first: '$course_info.hours' },
      students: { $sum: 1 }
  } },
  { $sort: { students: -1 } }
])
```

`$lookup` — це аналог `LEFT JOIN`, але виконується на стороні сервера без глобального планувальника; на великих колекціях він дорогий, тому `foreignField` має бути проіндексований.

### Проєкція, сортування, обмеження

```javascript
db.students.aggregate([
  { $project: {
      _id: 0,
      name: 1,
      grade: 1,
      courseCount: { $size: '$courses' }
  } },
  { $sort: { grade: -1 } },
  { $limit: 2 }
])
```

### Збирання значень у масив

```javascript
db.students.aggregate([
  { $group: {
      _id: '$city',
      names: { $push: '$name' },
      maxGrade: { $max: '$grade' }
  } },
  { $sort: { _id: 1 } }
])
```

### Агрегація на shop_big

Звіт за статусами замовлень:

```javascript
use shop_big

db.orders.aggregate([
  { $group: {
      _id: '$status',
      orders: { $sum: 1 },
      revenue: { $sum: '$total' }
  } },
  { $sort: { revenue: -1 } }
])
```

Топ-5 товарів за виручкою: `$unwind` розгортає позиції замовлень, `$group` підсумовує добуток ціни та кількості, `$lookup` додає дані з каталогу.

```javascript
db.orders.aggregate([
  { $match: { orderedAt: { $gte: new Date('2026-01-01') } } },
  { $unwind: '$items' },
  { $group: {
      _id: '$items.productId',
      revenue: { $sum: { $multiply: ['$items.price', '$items.quantity'] } },
      sold: { $sum: '$items.quantity' }
  } },
  { $sort: { revenue: -1 } },
  { $limit: 5 },
  { $lookup: {
      from: 'products',
      localField: '_id',
      foreignField: '_id',
      as: 'product'
  } },
  { $unwind: '$product' },
  { $project: { _id: 0, title: '$product.title', revenue: 1, sold: 1 } }
])
```

### Порахувати кількість результатів

```javascript
db.students.aggregate([
  { $match: { city: 'Київ' } },
  { $count: 'kyivStudents' }
])
```

## Відмінності від реляційної моделі

| Реляційна модель | MongoDB |
|---|---|
| Таблиця | Колекція |
| Рядок | Документ (BSON) |
| Колонка | Поле, якого може не бути в частині документів |
| Фіксована схема | Гнучка схема; валідація за бажанням через `$jsonSchema` |
| Первинний ключ `id` | Обов'язкове поле `_id` (ObjectId або власне значення) |
| `JOIN` | `$lookup` у конвеєрі або вкладення документів |
| Зовнішні ключі та каскади | Перевіряються застосунком; сервер не стежить за посиланнями |
| Транзакції на кілька таблиць | Атомарність одного документа гарантована завжди; багатодокументні транзакції підтримуються, але дорожчі |
| Нормалізація | Вкладення (embedding) для даних, які читають разом; посилання (referencing) для великих або спільних даних |
| `GROUP BY` | Стадія `$group` |
| Міграції схеми | Зміна форми документа без `ALTER TABLE` |

Коли вкладати, а коли посилатися:

- **Вкладати:** дані читаються разом, мають обмежений розмір і не змінюються незалежно (адреси, позиції замовлення, налаштування).
- **Посилатися:** дані великі, використовуються багатьма документами або оновлюються часто (каталог товарів, довідники).

## Типові помилки

1. **Очікування схеми.** `db.students.find({ grade: { $gt: 80 } })` не знайде документи, де `grade` збережено рядком `"85"`: MongoDB порівнює типи.
2. **Використання `$elemMatch` там, де потрібне просте вкладення.** Умови без `$elemMatch` перевіряються незалежно й дають хибні збіги на масивах.
3. **`$regex` без «якоря» та без індексу.** Регулярний вираз, що не починається з константи, не використовує btree-індекс і виконує `COLLSCAN`.
4. **`$lookup` без індексу на `foreignField`.** На колекції зі 100 000+ документів це найдорожча стадія конвеєра.
5. **Агрегація без початкового `$match`.** Фільтрація на початку конвеєра різко зменшує обсяг даних для `$group`.
6. **Великі масиви, що ростуть без обмежень.** Документ має ліміт 16 МБ; для історій використовуйте окрему колекцію (патерн «bucket» або окремі документи).
7. **Оновлення без `$set`.** `updateOne({...}, { grade: 90 })` замінить увесь документ, крім `_id`.
8. **Очікування, що `insertMany` вставить усе.** За замовчуванням порядок зберігається, і перша помилка зупиняє вставку; для продовження — `{ ordered: false }`.
9. **Використання `_id` як рядка після вставки.** `insertOne` повертає `ObjectId('...')`; для пошуку за ним потрібен саме `ObjectId`, а не рядок.
10. **Ігнорування `explain`.** Без перевірки `totalDocsExamined` легко створити індекс, який запит не використовує.

## Вправи

1. Додайте до `learn.students` документ студентки з Полтави з оцінкою 88 і курсом `SQL`, потім порахуйте кількість студентів у кожному місті.
2. Знайдіть студентів з оцінкою понад 85, які мають курс `SQL` (використайте `$and` або комбінацію умов), і виведіть лише `name`, `city`, `grade`.
3. У колекції `enrollments` знайдіть документи, де є елемент масиву з курсом `SQL` та оцінкою не менше 85, через `$elemMatch`.
4. Побудуйте конвеєр, який через `$unwind` і `$group` показує кількість студентів на кожному курсі, відсортовану за спаданням.
5. У `shop_big.orders` виконайте `explain('executionStats')` для фільтра за `customerId`, створіть індекс і покажіть, як змінився `totalDocsExamined`.

## Відповіді

1.

```javascript
db.students.insertOne({
  name: 'Оксана Романюк',
  email: 'oksana@example.com',
  city: 'Полтава',
  courses: ['SQL'],
  grade: 88
})

db.students.aggregate([
  { $group: { _id: '$city', students: { $sum: 1 } } },
  { $sort: { students: -1 } }
])
```

2.

```javascript
db.students.find(
  { $and: [{ grade: { $gt: 85 } }, { courses: 'SQL' }] },
  { _id: 0, name: 1, city: 1, grade: 1 }
)
```

3.

```javascript
db.enrollments.find({
  scores: { $elemMatch: { course: 'SQL', score: { $gte: 85 } } }
})
```

4.

```javascript
db.students.aggregate([
  { $unwind: '$courses' },
  { $group: { _id: '$courses', students: { $sum: 1 } } },
  { $sort: { students: -1, _id: 1 } }
])
```

5.

```javascript
use shop_big

db.orders.find({ customerId: 42 }).explain('executionStats').executionStats
// totalDocsExamined дорівнює кількості документів у колекції

db.orders.createIndex({ customerId: 1 })

db.orders.find({ customerId: 42 }).explain('executionStats').executionStats
// totalDocsExamined дорівнює кількості знайдених документів, stage = IXSCAN
```

## Пов'язані матеріали

- Агрегаційний конвеєр: [`../lessons/mongo_aggregation.js`](../lessons/mongo_aggregation.js)
- Індекси: [`../lessons/mongo_indexes.js`](../lessons/mongo_indexes.js)
- Веб-інтерфейс: http://localhost:8082 (Mongo Express)
- Веб-тренажер: http://localhost:8000/runner.php та http://localhost:8000/tasks.php
