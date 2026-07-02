# API ParsiPost - Customer Service

**Documentation Version:** ۱۴۰۴/۰۵/۰۱

**Base URL:** `https://post.pishgaman.top`

---

## Authentication & General Rules

- **Login** is required first to receive a token.
- Token is valid for **24 hours**.
- Send the token as **Bearer Token** in the `Authorization` header for all protected endpoints.
- In all authenticated requests, add `office` header with the `OfficeId` value received from Login.

### Common Response Format

| Field      | Type    | Description |
|------------|---------|-------------|
| `DATA`     | Object  | Response data (varies per endpoint) |
| `SUCCESS`  | Boolean | `true` if successful, otherwise `false` |
| `MESSAGES` | Object  | Contains message type and text |

---

## API Endpoints

| Description                          | Endpoint                                      | Method | Auth Required |
|--------------------------------------|-----------------------------------------------|--------|---------------|
| Login                                | `API/ORDERING/CLIENTORDER/LOGIN/`             | POST   | No            |
| Get Basic Info                       | `API/ORDERING/CLIENTORDER/GETAPIORDERBASICINFO/` | GET    | Yes           |
| Get All Extra Services               | `API/ORDERING/CLIENTORDER/GETALLEXTRASERVICE` | GET    | Yes           |
| Get Service Types (Tariffs)          | `API/ORDERING/CLIENTORDER/GETAPISERVICETYPE`  | GET    | Yes           |
| Calculate Price                      | `API/ORDERING/CLIENTORDER/GETPRICE/`          | POST   | Yes           |
| Get All Products                     | `API/ORDERING/CLIENTORDER/GETALLPRODUCTS/`    | POST   | Yes           |
| Get All Receivers                    | `API/ORDERING/CLIENTORDER/GETALLRECEIVERS/`   | POST   | Yes           |
| Save & Pay Order                     | `API/ORDERING/CLIENTORDER/SAVE/`              | POST   | Yes           |

---

## 1. Login

**Endpoint:** `API/ORDERING/CLIENTORDER/LOGIN/`

**Method:** `POST`

### Input

| Field        | Required | Type   | Description |
|--------------|----------|--------|-------------|
| `PASSWORD`   | Yes      | String | Password |
| `MOBILE`     | Yes      | String | Mobile number (Username) |
| `TYPE`       | Yes      | String | Login type (`mobile` by default) |
| `SUPPLIERID` | Yes      | String | Supplier ID |

### Output

| Field            | Type   | Description |
|------------------|--------|-------------|
| `USERID`         | GUID   | User ID |
| `FIRSTNAME`      | String | First name |
| `LASTNAME`       | String | Last name |
| `ACTIVESESSIONS` | Object | Active sessions |
| `USERACCESSES`   | Object | User permissions |
| `TOKENDATA`      | Object | Contains `access_token` and `OfficeId` |

---

## 2. Get Basic Info

**Endpoint:** `API/ORDERING/CLIENTORDER/GETAPIORDERBASICINFO/`

**Method:** `GET`

**Input:** `OFFICEID` (GUID - Required)

### Output

| Field              | Type   | Description |
|--------------------|--------|-------------|
| `PROVINCES`        | Object | Provinces and cities |
| `PARCELTYPES`      | Object | Parcel types |
| `INSURANCETYPES`   | Object | Insurance types |
| `BOXTYPES`         | Object | Box/packaging types |
| `PAYMENTTYPES`     | Object | Payment methods |
| `EXTRASERVICES`    | Object | Extra services |

---

## 3. Get All Extra Services

**Endpoint:** `API/ORDERING/CLIENTORDER/GETALLEXTRASERVICE`

**Method:** `GET`

**Input:** None

### Output (Array)

| Field               | Type    | Description |
|---------------------|---------|-------------|
| `ID`                | GUID    | ID |
| `SECONDARYID`       | Int     | Secondary ID |
| `SERVICETYPEID`     | GUID    | Service type ID |
| `TITLE`             | String  | Title |
| `NEEDDATA`          | Boolean | Needs additional data |
| `ISSELECTABLE`      | Boolean | Selectable |
| `PRICEDESCRIPTION`  | String  | Price description |
| `NEEDDATAS`         | Object  | Required data fields |

---

## 4. Get Service Types (Tariffs)

**Endpoint:** `API/ORDERING/CLIENTORDER/GETAPISERVICETYPE`

**Method:** `GET`

### Input

| Field             | Required | Type | Description |
|-------------------|----------|------|-------------|
| `SENDER CITYID`   | Yes      | GUID | Sender city ID |
| `RECEIVER CITYID` | Yes      | GUID | Receiver city ID |
| `CONTRACTID`      | Yes      | GUID | Contract ID |

*(Detailed output fields available in original document)*

---

## 5. Calculate Price

**Endpoint:** `API/ORDERING/CLIENTORDER/GETPRICE/`

**Method:** `POST`

### Input

| Field                     | Required | Type     | Description |
|---------------------------|----------|----------|-------------|
| `CONTRACTID`              | Yes      | GUID     | Contract ID |
| `ISFOREIGN`               | Yes      | Boolean  | Is foreign parcel |
| `SERVICETYPEID`           | Yes      | GUID     | Service type |
| `INSURANCETYPEID`         | Yes      | GUID     | Insurance type |
| `DEADLINEID`              | Yes      | GUID     | Deadline ID |
| `SENDERCITYID`            | Yes      | GUID     | Sender city |
| `SENDERPOSTALCODE`        | Yes      | String   | Sender postal code |
| `RECEIVERCITYID`          | Yes      | GUID     | Receiver city |
| `PARCELTYPEID`            | Yes      | GUID     | Parcel type |
| `BOXTYPEID`               | Yes      | GUID     | Box type |
| `WEIGHT`                  | Yes      | Decimal  | Weight |
| `PRICEVALUE`              | Yes      | Long     | Parcel value |
| `PACKINGPRICE`            | Yes      | Long     | Packing price |
| `COUPONCODE`              | No       | String   | Coupon code |
| `HEIGHT` / `WIDTH` / `LENGTH` | No   | Decimal  | Dimensions |
| `PARCELEXTRASERVICES`     | No       | Array    | Extra services |

---

## 6. Get All Products

**Endpoint:** `API/ORDERING/CLIENTORDER/GETALLPRODUCTS/`

**Method:** `POST`

### Input

| Field              | Required | Type   | Description |
|--------------------|----------|--------|-------------|
| `TITLE`            | No       | String | Title |
| `CODE`             | No       | String | Product code |
| `CATEGORY`         | No       | String | Category |
| `OFFICEID`         | Yes      | GUID   | Office ID |
| `PARCELTYPEID`     | No       | GUID   | Parcel type |
| `BOXTYPEID`        | No       | GUID   | Box type |
| `INSURANCETYPEID`  | No       | GUID   | Insurance type |
| `PAGEINDEX`        | No       | Int    | Page index |
| `PAGESIZE`         | No       | Int    | Page size |

### Output

| Field         | Type   | Description |
|---------------|--------|-------------|
| `TOTALITEMS`  | Int    | Total count |
| `ITEMS`       | Array  | List of products |

---

## 7. Get All Receivers

**Endpoint:** `API/ORDERING/CLIENTORDER/GETALLRECEIVERS/`

**Method:** `POST`

Similar to Get Products with filters: `LASTNAME`, `POSTALCODE`, `NATIONALCODE`, `MOBILE`, `RECEIVERCITYID`, etc.

---

## 8. Save & Pay Order

**Endpoint:** `API/ORDERING/CLIENTORDER/SAVE/`

**Method:** `POST`

This endpoint contains complex nested structures (`PARCELGROUPS` → `PARCELS`).

**Main Input Fields:**

- Sender information (`SENDERFIRSTNAME`, `SENDERLASTNAME`, `SENDERMOBILE`, etc.)
- `OFFICEID`, `CONTRACTID`, `PAYMENTTYPEID`
- `IAMTHESENDER`
- `PARCELGROUPS` (Array)

**ParcelGroup Fields:**
- Receiver details
- `PARCELS` (Array of individual parcels)
- Extra services

**Parcel Fields:**
- `WEIGHT`, `CONTENT`, `PARCELTYPEID`, dimensions, `INSURANCETYPEID`, etc.

---

**End of Document**

> **Note:** For complete field lists of complex endpoints (especially **Save Order**), refer to the original PDF pages 9–10.