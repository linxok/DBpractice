// Тема: агрегаційний конвеєр MongoDB
// Виконуйте:
//   docker cp lessons/mongo_aggregation.js learn-mongo:/tmp/lesson.js
//   docker exec learn-mongo mongosh -u root -p student --authenticationDatabase admin --file /tmp/lesson.js

const learn = db.getSiblingDB('learn');
const big = db.getSiblingDB('shop_big');

// 1. Фільтр і групування на навчальній базі
print('--- Студенти за містами (learn)');
printjson(learn.students.aggregate([
  { $match: { grade: { $gte: 80 } } },
  { $group: { _id: '$city', avgGrade: { $avg: '$grade' }, students: { $sum: 1 } } },
  { $sort: { avgGrade: -1 } }
]).toArray());

// 2. Розгортання масиву $unwind
print('--- Курси за популярністю (learn)');
printjson(learn.students.aggregate([
  { $unwind: '$courses' },
  { $group: { _id: '$courses', students: { $sum: 1 } } },
  { $sort: { students: -1, _id: 1 } }
]).toArray());

// 3. Об'єднання колекцій $lookup
print('--- Студенти з назвами курсів (learn)');
printjson(learn.students.aggregate([
  { $unwind: '$courses' },
  {
    $lookup: {
      from: 'courses',
      localField: 'courses',
      foreignField: 'code',
      as: 'course'
    }
  },
  { $unwind: '$course' },
  { $project: { _id: 0, student: '$name', course: '$course.title', grade: 1 } },
  { $sort: { student: 1, course: 1 } }
]).toArray());

// 4. Великі дані: сумарні продажі за статусами (shop_big)
print('--- Замовлення за статусами (shop_big)');
printjson(big.orders.aggregate([
  {
    $group: {
      _id: '$status',
      orders: { $sum: 1 },
      total: { $sum: '$total' },
      avgTotal: { $avg: '$total' }
    }
  },
  { $sort: { total: -1 } }
]).toArray());

// 5. Розгортання вкладених позицій замовлення (shop_big)
print('--- Топ-5 товарів за кількістю проданих одиниць (shop_big)');
printjson(big.orders.aggregate([
  { $unwind: '$items' },
  {
    $group: {
      _id: '$items.productId',
      title: { $first: '$items.title' },
      units: { $sum: '$items.quantity' }
    }
  },
  { $sort: { units: -1, _id: 1 } },
  { $limit: 5 }
]).toArray());

// 6. Поєднання $match і $group з індексом
print('--- Замовлення завершені (status = done) за місяцями (shop_big)');
printjson(big.orders.aggregate([
  { $match: { status: 'done' } },
  {
    $group: {
      _id: { $dateToString: { format: '%Y-%m', date: '$orderedAt' } },
      orders: { $sum: 1 }
    }
  },
  { $sort: { _id: 1 } },
  { $limit: 6 }
]).toArray());
