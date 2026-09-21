// Тема: індекси та explain у MongoDB
// Виконуйте:
//   docker cp lessons/mongo_indexes.js learn-mongo:/tmp/lesson.js
//   docker exec learn-mongo mongosh -u root -p student --authenticationDatabase admin --file /tmp/lesson.js

const big = db.getSiblingDB('shop_big');

// 1. План виконання без індексу
print('--- explain без індексу (customerId = 42)');
const before = big.orders.find({ customerId: 42 }).explain('executionStats');
printjson({
  stage: before.queryPlanner.winningPlan.stage,
  docsExamined: before.executionStats.totalDocsExamined,
  returned: before.executionStats.nReturned
});

// 2. Створення індексу
print('--- CREATE INDEX { customerId: 1 }');
printjson(big.orders.createIndex({ customerId: 1 }));

// 3. План із індексом
print('--- explain після створення індексу');
const after = big.orders.find({ customerId: 42 }).explain('executionStats');
printjson({
  stage: after.queryPlanner.winningPlan.stage,
  docsExamined: after.executionStats.totalDocsExamined,
  keysExamined: after.executionStats.totalKeysExamined,
  returned: after.executionStats.nReturned
});

// 4. Складений індекс і сортування
print('--- CREATE INDEX { customerId: 1, orderedAt: -1 }');
printjson(big.orders.createIndex({ customerId: 1, orderedAt: -1 }));

const compound = big.orders
  .find({ customerId: 42 })
  .sort({ orderedAt: -1 })
  .explain('executionStats');
printjson({
  stage: compound.queryPlanner.winningPlan.stage,
  docsExamined: compound.executionStats.totalDocsExamined
});

// 5. Список індексів колекції
print('--- Індекси колекції orders');
printjson(big.orders.getIndexes());

// 6. Прибирання створених індексів, щоб повернути початковий стан
big.orders.dropIndex({ customerId: 1 });
big.orders.dropIndex({ customerId: 1, orderedAt: -1 });
print('--- Індекси після прибирання');
printjson(big.orders.getIndexes());

// 7. Повнотекстовий пошук у MongoDB
print('--- TEXT INDEX на customers.name');
printjson(big.customers.createIndex({ name: 'text' }));
printjson(big.customers.find(
  { $text: { $search: 'Клієнт 777' } },
  { _id: 0, name: 1, city: 1 }
).limit(3).toArray());
big.customers.dropIndex({ name: 'text' });
