import 'package:shortzz/common/functions/json_parse.dart';

class WithdrawModel {
  WithdrawModel({
    this.status,
    this.message,
    this.data,
  });

  WithdrawModel.fromJson(dynamic json) {
    status = asBoolOrNull(json['status']);
    message = asStringOrNull(json['message']);
    if (json['data'] != null) {
      data = [];
      json['data'].forEach((v) {
        data?.add(Withdraw.fromJson(v));
      });
    }
  }

  bool? status;
  String? message;
  List<Withdraw>? data;

  Map<String, dynamic> toJson() {
    final map = <String, dynamic>{};
    map['status'] = status;
    map['message'] = message;
    if (data != null) {
      map['data'] = data?.map((v) => v.toJson()).toList();
    }
    return map;
  }
}

class Withdraw {
  Withdraw({
    this.id,
    this.userId,
    this.requestNumber,
    this.gateway,
    this.account,
    this.operator,
    this.amount,
    this.fee,
    this.coins,
    this.coinValue,
    this.status,
    this.reason,
    this.createdAt,
    this.updatedAt,
  });

  Withdraw.fromJson(dynamic json) {
    id = asNumOrNull(json['id']);
    userId = asNumOrNull(json['user_id']);
    requestNumber = asStringOrNull(json['request_number']);
    gateway = asStringOrNull(json['gateway']);
    account = asStringOrNull(json['account']);
    operator = asStringOrNull(json['operator']);
    amount = asStringOrNull(json['amount']);
    fee = asStringOrNull(json['fee']);
    coins = asNumOrNull(json['coins']);
    coinValue = asNumOrNull(json['coin_value']);
    status = asNumOrNull(json['status']);
    reason = asStringOrNull(json['reason']);
    createdAt = asStringOrNull(json['created_at']);
    updatedAt = asStringOrNull(json['updated_at']);
  }

  num? id;
  num? userId;
  String? requestNumber;
  String? gateway;
  String? account;

  /// Mobile-money network of an earnings withdrawal (airtel | mtn | zamtel).
  String? operator;

  /// Kwacha, a decimal string ("60.00").
  String? amount;
  String? fee;
  num? coins;
  num? coinValue;

  /// 0 pending, 1 completed, 2 rejected, 3 processing, 4 failed.
  num? status;

  /// Why a withdrawal was rejected or failed, shown to the creator.
  String? reason;
  String? createdAt;
  String? updatedAt;

  Map<String, dynamic> toJson() {
    final map = <String, dynamic>{};
    map['id'] = id;
    map['user_id'] = userId;
    map['request_number'] = requestNumber;
    map['gateway'] = gateway;
    map['account'] = account;
    map['operator'] = operator;
    map['amount'] = amount;
    map['fee'] = fee;
    map['coins'] = coins;
    map['coin_value'] = coinValue;
    map['status'] = status;
    map['reason'] = reason;
    map['created_at'] = createdAt;
    map['updated_at'] = updatedAt;
    return map;
  }
}
