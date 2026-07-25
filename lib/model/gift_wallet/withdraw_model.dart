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
    this.amount,
    this.coins,
    this.coinValue,
    this.status,
    this.createdAt,
    this.updatedAt,
  });

  Withdraw.fromJson(dynamic json) {
    id = asNumOrNull(json['id']);
    userId = asNumOrNull(json['user_id']);
    requestNumber = asStringOrNull(json['request_number']);
    gateway = asStringOrNull(json['gateway']);
    account = asStringOrNull(json['account']);
    amount = asStringOrNull(json['amount']);
    coins = asNumOrNull(json['coins']);
    coinValue = asNumOrNull(json['coin_value']);
    status = asNumOrNull(json['status']);
    createdAt = asStringOrNull(json['created_at']);
    updatedAt = asStringOrNull(json['updated_at']);
  }

  num? id;
  num? userId;
  String? requestNumber;
  String? gateway;
  String? account;
  String? amount;
  num? coins;
  num? coinValue;
  num? status;
  String? createdAt;
  String? updatedAt;

  Map<String, dynamic> toJson() {
    final map = <String, dynamic>{};
    map['id'] = id;
    map['user_id'] = userId;
    map['request_number'] = requestNumber;
    map['gateway'] = gateway;
    map['account'] = account;
    map['amount'] = amount;
    map['coins'] = coins;
    map['coin_value'] = coinValue;
    map['status'] = status;
    map['created_at'] = createdAt;
    map['updated_at'] = updatedAt;
    return map;
  }
}
